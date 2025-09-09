<?php
namespace App\Controller;

use App\Entity\Child;
use App\Entity\Message;
use App\Entity\OutingRegistration;
use App\Repository\AttendanceRepository;
use App\Repository\ChildRepository;
use App\Repository\MessageRepository;
use App\Repository\OutingRegistrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[IsGranted('ROLE_PARENT')]
final class AccountController extends AbstractController
{
    #[Route('/mon-compte', name: 'app_account', methods: ['GET'])]
    public function index(
        Request $req,
        ChildRepository $children,
        AttendanceRepository $attendances,
        MessageRepository $messages,
        OutingRegistrationRepository $regs
    ) {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $profile = method_exists($user, 'getProfile') ? $user->getProfile() : null;

        // ----- Enfants rattachés -----
        $kids = $children->createQueryBuilder('c')
            ->andWhere('c.parent = :p')->setParameter('p', $profile)
            ->orderBy('c.firstName','ASC')->getQuery()->getResult();

        if (!$kids) {
            return $this->render('account/empty.html.twig');
        }

        // ----- Enfant courant -----
        $cid = (int) $req->query->get('enfant', $kids[0]->getId());
        /** @var Child|null $current */
        $current = $children->find($cid);
        if (!$current || $current->getParent() !== $profile) {
            $current = $kids[0];
            $cid = $current->getId();
        }

        // ----- Onglet -----
        $tab = (string) $req->query->get('tab', 'overview');
        $tab = in_array($tab, ['overview','presences','sorties','messages','settings'], true) ? $tab : 'overview';

        // ----- Année scolaire sélectionnée -----
        $askedYear = $req->query->getInt('annee', $this->defaultSchoolStartYear());

        // ----- Données communes -----
        $data = [
            'presenceRate30' => 0,
            'schoolYear'     => $askedYear,
            'cal'            => null,   // calendrier du mois (pour l’onglet Présences)
            'messages'       => [],
            'messagesRecent' => [],
            'upcoming'       => [],
            'past'           => [],
            'signedRate'     => 0,
        ];

        // KPI Présences (30 derniers jours)
        $data['presenceRate30'] = (int) round($attendances->presenceRate(
            $cid,
            new \DateTimeImmutable('today -30 days', new \DateTimeZone('Europe/Paris')),
            new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'))
        ));

        $tz = new \DateTimeZone('Europe/Paris');
        $periodStart = new \DateTimeImmutable('today -30 days', $tz);
        $periodEnd   = new \DateTimeImmutable('today', $tz);

        $pas = $attendances->presentAbsentStats($cid, $periodStart, $periodEnd);

        $data['presentCount30'] = $pas['present'];
        $data['absentCount30']  = $pas['absent'];

        $totalPA = $pas['total'];
        $data['presencePct30'] = $totalPA ? (int) round($pas['present'] * 100 / $totalPA) : 0;
// 👇 garantis la complémentarité après arrondi
        $data['absencePct30']  = $totalPA ? (100 - $data['presencePct30']) : 0;



        // ----- Présences : calendrier serveur, samedis uniquement -----
        if ($tab === 'overview' || $tab === 'presences') {
            [$syStart, $syEnd] = $this->schoolYearRange($askedYear);

            // mois demandé (YYYY-MM), sinon “mois courant” clampé dans l’année scolaire
            $mois = (string) $req->query->get('mois', '');
            if ($mois && preg_match('/^\d{4}-\d{2}$/', $mois)) {
                $monthStart = \DateTimeImmutable::createFromFormat('Y-m-d', $mois.'-01', new \DateTimeZone('Europe/Paris'));
            } else {
                $today = new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
                if ($today < $syStart) $today = $syStart;
                if ($today > $syEnd)   $today = $syEnd;
                $monthStart = $today->modify('first day of this month');
            }

            // bornes affichage : 1er jour du mois à 00:00 → dernier jour du mois à 23:59:59
            $monthEnd = $monthStart->modify('last day of this month')->setTime(23,59,59);

            // présences du mois
            $list = $attendances->findForChild($cid, $monthStart, $monthEnd);

            $data['cal'] = $this->buildMonthView($monthStart, $list, $syStart, $syEnd);
        }

        // ----- Messages -----
        if ($tab === 'overview' || $tab === 'messages') {
            $qb = $messages->createQueryBuilder('m')
                ->andWhere('m.child = :c')->setParameter('c', $cid)
                ->orderBy('m.createdAt','DESC');

            $full = $tab === 'messages'
                ? (clone $qb)->setMaxResults(200)->getQuery()->getResult()
                : (clone $qb)->setMaxResults(20)->getQuery()->getResult();

            $map = static fn(Message $m) => [
                'id'        => $m->getId(),
                'from'      => $m->getSender(), // staff|parent
                'body'      => $m->getBody(),
                'createdAt' => $m->getCreatedAt(),
                'readAt'    => $m->getReadAt(),
            ];

            if ($tab === 'messages') {
                $data['messages'] = array_reverse(array_map($map, $full)); // chronologique
            } else {
                // non lus d’abord
                usort($full, function(Message $a, Message $b) {
                    $ar = $a->getReadAt() !== null;
                    $br = $b->getReadAt() !== null;
                    return $ar === $br ? ($b->getCreatedAt() <=> $a->getCreatedAt()) : ($ar <=> $br);
                });
                $data['messagesRecent'] = array_map($map, array_slice($full, 0, 5));
            }
        }

        // ----- Sorties (à venir + passées) + taux signé -----
        if ($tab === 'overview' || $tab === 'sorties') {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

            $up = $regs->createQueryBuilder('r')
                ->join('r.outing','o')
                ->andWhere('r.child = :c')->setParameter('c',$cid)
                ->andWhere('o.startsAt >= :now')->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
                ->orderBy('o.startsAt','ASC')
                ->getQuery()->getResult();

            $past = [];
            if ($tab === 'sorties') {
                $past = $regs->createQueryBuilder('r')
                    ->join('r.outing','o')
                    ->andWhere('r.child = :c')->setParameter('c',$cid)
                    ->andWhere('o.startsAt < :now')->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
                    ->orderBy('o.startsAt','DESC')
                    ->setMaxResults(20)
                    ->getQuery()->getResult();
            }

            $signed = 0;
            $totalUpcoming = 0;
            /** @var OutingRegistration $r */
            foreach ($up as $r) {
                $totalUpcoming++;
                if ($r->getSignedAt() || in_array($r->getStatus(), ['confirmed','attended'], true)) {
                    $signed++;
                }
            }
            $data['signedRate']    = $totalUpcoming ? (int) round($signed * 100 / $totalUpcoming) : 0;
            $data['signedCount']   = $signed;
            $data['totalUpcoming'] = $totalUpcoming;

            $data['upcoming'] = $up;
            $data['past']     = $past;
        }

        return $this->render('account/index.html.twig', [
            'kids'    => $kids,
            'current' => $current,
            'tab'     => $tab,
            'data'    => $data,
        ]);
    }

    #[Route('/mon-compte/messages/envoyer', name: 'app_account_message_send', methods: ['POST'])]
    public function messageSend(
        Request $req,
        ChildRepository $children,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrf
    ): RedirectResponse {
        $cid = (int) $req->request->get('child_id');
        $token = (string) $req->request->get('_token');
        if (!$csrf->isTokenValid(new CsrfToken('send_message', $token))) {
            $this->addFlash('error','Jeton CSRF invalide.');
            return $this->redirectToRoute('app_account', ['enfant'=>$cid, 'tab'=>'messages'], 303);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $child = $children->find($cid);
        if (!$child || $child->getParent() !== $user->getProfile()) {
            $this->addFlash('error','Enfant invalide.');
            return $this->redirectToRoute('app_account', ['tab'=>'messages'], 303);
        }

        $body = trim((string) $req->request->get('text',''));
        if ($body === '') {
            $this->addFlash('error','Message vide.');
            return $this->redirectToRoute('app_account', ['enfant'=>$cid, 'tab'=>'messages'], 303);
        }

        $m = (new Message())
            ->setChild($child)
            ->setSubject('Message parent')
            ->setBody($body)
            ->setSender('parent');

        $em->persist($m);
        $em->flush();

        $this->addFlash('success','Message envoyé.');
        return $this->redirectToRoute('app_account', ['enfant'=>$cid, 'tab'=>'messages'], 303);
    }

    #[Route('/mon-compte/sorties/{id}/autoriser', name: 'app_account_outing_sign_form', methods: ['GET'])]
    public function outingSignForm(
        int $id,
        OutingRegistrationRepository $regs
    ) {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        /** @var OutingRegistration|null $reg */
        $reg = $regs->find($id);
        if (!$reg || $reg->getChild()->getParent() !== $user->getProfile()) {
            throw $this->createNotFoundException('Inscription introuvable.');
        }

        return $this->render('account/outing_sign.html.twig', [
            'reg' => $reg,
        ]);
    }

    #[Route('/mon-compte/sorties/{id}/signer', name: 'app_account_outing_sign', methods: ['POST'])]
    public function outingSign(
        int $id,
        Request $req,
        OutingRegistrationRepository $regs,
        EntityManagerInterface $em,
        CsrfTokenManagerInterface $csrf
    ): RedirectResponse {
        $token = (string) $req->request->get('_token');
        if (!$csrf->isTokenValid(new CsrfToken('sign_outing_'.$id, $token))) {
            $this->addFlash('error','Jeton CSRF invalide.');
            return $this->redirectToRoute('app_account', ['tab'=>'sorties'], 303);
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        /** @var OutingRegistration|null $reg */
        $reg = $regs->find($id);
        if (!$reg || $reg->getChild()->getParent() !== $user->getProfile()) {
            $this->addFlash('error','Inscription introuvable.');
            return $this->redirectToRoute('app_account', ['tab'=>'sorties'], 303);
        }

        // ✅ Vérification du checkbox "consent" (name="consent" dans le formulaire)
        if (!$req->request->has('consent')) {
            $this->addFlash('error','Vous devez autoriser la participation et certifier être représentant légal.');
            return $this->redirectToRoute('app_account_outing_sign_form', ['id' => $id], 303);
        }

        // Champs requis
        $name  = trim((string) $req->request->get('name',''));
        $phone = trim((string) $req->request->get('phone',''));
        $health= trim((string) $req->request->get('health',''));
        if ($name === '' || $phone === '') {
            $this->addFlash('error','Nom et téléphone requis.');
            return $this->redirectToRoute('app_account_outing_sign_form', ['id'=>$id], 303);
        }

        // Enregistrement
        $reg->setSignatureName($name)
            ->setSignaturePhone($phone)
            ->setHealthNotes($health ?: null)
            ->setSignedAt(new \DateTimeImmutable())
            ->setStatus('confirmed');

        // Si tu as un booléen en base, ex. setLegalConsent(true) :
        // $reg->setLegalConsent(true);

        $em->flush();

        $this->addFlash('success','Autorisation enregistrée.');
        return $this->redirectToRoute('app_account', [
            'enfant' => $reg->getChild()->getId(),
            'tab'    => 'sorties'
        ], 303);
    }

    // ========= Helpers calendrier / année scolaire =========

    /** 1er sept N 00:00:00 → 31 août N+1 23:59:59 */
    private function schoolYearRange(int $startYear): array
    {
        $tz = new \DateTimeZone('Europe/Paris');
        $start = (new \DateTimeImmutable(sprintf('%d-09-01 00:00:00', $startYear), $tz));
        $end   = (new \DateTimeImmutable(sprintf('%d-08-31 23:59:59', $startYear + 1), $tz));
        return [$start, $end];
    }

    /** Septembre → Août */
    private function defaultSchoolStartYear(\DateTimeImmutable $today = null): int
    {
        $today = $today ?? new \DateTimeImmutable('today', new \DateTimeZone('Europe/Paris'));
        $y = (int)$today->format('Y');
        $m = (int)$today->format('n');
        return ($m >= 9) ? $y : ($y - 1);
    }

    /** Libellé "mois année" sans intl */
    private static function frMonthYear(\DateTimeImmutable $d): string
    {
        $mois = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
        return $mois[(int)$d->format('n') - 1] . ' ' . $d->format('Y');
    }

    /**
     * Construit la vue du mois : samedis affichés avec statut, autres jours "off".
     * Retourne :
     *  - label (ex. "septembre 2025")
     *  - monthKey ("YYYY-MM")
     *  - startPad (0..6)
     *  - days[]: { d, isSaturday, status }
     *  - prev ("YYYY-MM") ou null si avant début d'année scolaire
     *  - next ("YYYY-MM") ou null si après fin d'année scolaire
     */
    private function buildMonthView(\DateTimeImmutable $monthStart, array $attendances, \DateTimeImmutable $syStart, \DateTimeImmutable $syEnd): array
    {
        // map ISO -> status
        $map = [];
        foreach ($attendances as $a) {
            $map[$a->getDate()->format('Y-m-d')] = $a->getStatus(); // present|absent|late|excused
        }

        $y = (int)$monthStart->format('Y');
        $m = (int)$monthStart->format('m');
        $dim = (int)$monthStart->format('t');

        $days = [];
        for ($d = 1; $d <= $dim; $d++) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $y, $m, $d), new \DateTimeZone('Europe/Paris'));
            $dow  = (int)$date->format('N'); // 1=lundi .. 7=dimanche
            $isSaturday = ($dow === 6);
            $iso = $date->format('Y-m-d');
            $status = $isSaturday ? ($map[$iso] ?? 'none') : 'off';
            $days[] = [
                'd' => $d,
                'isSaturday' => $isSaturday,
                'status' => $status,
            ];
        }

        // padding départ (lundi=1)
        $firstDow = (int)$monthStart->format('N');
        $startPad = $firstDow - 1;

        // prev/next clampés à l’année scolaire
        $prevStart = $monthStart->modify('first day of previous month');
        $nextStart = $monthStart->modify('first day of next month');

        $prevKey = ($prevStart >= $syStart) ? $prevStart->format('Y-m') : null;
        $nextKey = ($nextStart <= $syEnd->modify('first day of this month')) ? $nextStart->format('Y-m') : null;

        return [
            'label'    => self::frMonthYear($monthStart),
            'monthKey' => $monthStart->format('Y-m'),
            'startPad' => $startPad,
            'days'     => $days,
            'prev'     => $prevKey,
            'next'     => $nextKey,
        ];
    }

    #[Route('/mon-compte/sorties/{id}/imprimer', name: 'app_account_outing_print', methods: ['GET'])]
    public function outingPrint(
        int $id,
        OutingRegistrationRepository $regs
    ) {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        /** @var \App\Entity\OutingRegistration|null $reg */
        $reg = $regs->find($id);
        if (!$reg || $reg->getChild()->getParent() !== $user->getProfile()) {
            throw $this->createNotFoundException('Inscription introuvable.');
        }

        return $this->render('account/outing_print.html.twig', [
            'reg' => $reg,
        ]);
    }

}
