<?php
namespace App\Controller;

use App\Entity\Child;
use App\Entity\Message;
use App\Entity\OutingRegistration;
use App\Repository\AttendanceRepository;
use App\Repository\ChildRepository;
use App\Repository\MessageRepository;
use App\Repository\OutingRegistrationRepository;
use App\Repository\OutingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use App\Service\PdfGenerator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Doctrine\DBAL\LockMode;

#[IsGranted('ROLE_PARENT')]
final class AccountController extends AbstractController
{
    #[Route('/mon-compte', name: 'app_account', methods: ['GET'])]
    public function index(
        Request $req,
        ChildRepository $children,
        AttendanceRepository $attendances,
        MessageRepository $messages,
        OutingRegistrationRepository $regs,
        OutingRepository $outingRepo
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
        $tab = in_array($tab, ['overview','presences','sorties','messages','settings','assurance'], true) ? $tab : 'overview';

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
            'upcomings'      => [],
            'past'           => [],
            'signedRate'     => 0,
            'signedCounts'   => [],     // ← initialisation pour sécurité
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

// ----- Sorties (toutes les sorties affichées) + KPI signé + invitations enfant -----
        if ($tab === 'overview' || $tab === 'sorties') {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));

            // 1) Inscriptions de CET enfant (servent aux KPI + savoir si "invité")
            $childRegs = $regs->createQueryBuilder('r')
                ->leftJoin('r.outing', 'o')->addSelect('o')
                ->andWhere('r.child = :c')->setParameter('c', $cid)
                ->orderBy('o.startsAt', 'ASC')
                ->getQuery()->getResult();

            // 2) Upcoming/past POUR L’ENFANT (garde ta logique existante pour l’overview)
            $up = array_values(array_filter($childRegs, static function(OutingRegistration $r) use ($now) {
                $o = $r->getOuting();
                return $o && $o->getStartsAt() >= $now;
            }));

            $past = [];
            if ($tab === 'sorties') {
                $past = array_values(array_filter($childRegs, static function(OutingRegistration $r) use ($now) {
                    $o = $r->getOuting();
                    return $o && $o->getStartsAt() < $now;
                }));
                // tri desc pour les passées (comme avant)
                usort($past, static function(OutingRegistration $a, OutingRegistration $b) {
                    return $b->getOuting()->getStartsAt() <=> $a->getOuting()->getStartsAt();
                });
                $past = array_slice($past, 0, 20);
            }

            // 3) KPI signé (sur les "upcoming" de l’enfant)
            $signed = 0;
            $totalUpcoming = 0;
            foreach ($up as $r) {
                $totalUpcoming++;
                if ($r->getSignedAt() || in_array($r->getStatus(), ['confirmed','attended'], true)) {
                    $signed++;
                }
            }
            $data['signedRate']    = $totalUpcoming ? (int) round($signed * 100 / $totalUpcoming) : 0;
            $data['signedCount']   = $signed;
            $data['totalUpcoming'] = $totalUpcoming;

            // 4) Upcoming/past (compatibilité avec le reste du template)
            $data['upcoming']  = $up;
            $data['past']      = $past;

            // Option que tu avais déjà
            $data['upcomings'] = $outingRepo->findUpcoming(3);

            // 5) TOUTES les sorties (pour l’affichage global dans l’onglet "sorties")
            //    Tri décroissant par date (les plus récentes/à venir d’abord)
            $allOutings = $outingRepo->createQueryBuilder('o')
                ->orderBy('o.startsAt','DESC')
                ->getQuery()->getResult();
            $data['allOutings'] = $allOutings;

            // 6) Map "invitation" pour CET enfant : outingId => OutingRegistration
            $invitedMap = [];
            foreach ($childRegs as $rr) {
                $oid = $rr->getOuting()?->getId();
                if ($oid) {
                    $invitedMap[$oid] = $rr;
                }
            }
            $data['invitedMap'] = $invitedMap;

            // 7) Comptes de signatures par sortie (globaux) pour afficher "(n place(s) ...)"
            $outingIds = array_map(static fn($o) => $o->getId(), $allOutings);
            $data['signedCounts'] = !empty($outingIds)
                ? $regs->countSignedByOutingIds($outingIds)   // ← méthode ajoutée dans OutingRegistrationRepository
                : [];
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

        // ✅ Consent obligatoire
        if (!$req->request->has('consent')) {
            $this->addFlash('error','Vous devez autoriser la participation et certifier être représentant légal.');
            return $this->redirectToRoute('app_account_outing_sign_form', ['id' => $id], 303);
        }

        // Champs requis (on les lit AVANT la transaction pour pouvoir quitter proprement si manquants)
        $name   = trim((string) $req->request->get('name',''));
        $phone  = trim((string) $req->request->get('phone',''));
        $health = trim((string) $req->request->get('health',''));
        if ($name === '' || $phone === '') {
            $this->addFlash('error','Nom et téléphone requis.');
            return $this->redirectToRoute('app_account_outing_sign_form', ['id'=>$id], 303);
        }
        $signatureData = (string) $req->request->get('signature', '');

        // ✅ Contrôle capacité avec verrou sous transaction (seulement si capacité définie)
        $outing   = $reg->getOuting();
        $capacity = method_exists($outing, 'getCapacity') ? $outing->getCapacity() : null;

        if ($capacity !== null) {
            $conn = $em->getConnection();
            $conn->beginTransaction(); // ← ouvrir la transaction requise par le lock pessimiste

            try {
                // Verrouille l'Outing pour éviter les dépassements concurrents
                $em->lock($outing, LockMode::PESSIMISTIC_WRITE);

                // Compte uniquement les inscriptions SIGNÉES (signedAt non nul)
                $signedCount = (int) $regs->createQueryBuilder('r')
                    ->select('COUNT(r.id)')
                    ->andWhere('r.outing = :o')
                    ->andWhere('r.signedAt IS NOT NULL')
                    ->setParameter('o', $outing)
                    ->getQuery()
                    ->getSingleScalarResult();

                if ($signedCount >= $capacity) {
                    $conn->rollBack();
                    $this->addFlash('warning', 'Désolé, cette sortie est complète (limite d’enfants atteinte).');
                    return $this->redirectToRoute('app_account', [
                        'enfant' => $reg->getChild()->getId(),
                        'tab'    => 'sorties'
                    ], 303);
                }

                // ✅ On enregistre la signature SOUS VERROU puis on flush et commit
                $reg->setSignatureName($name)
                    ->setSignaturePhone($phone)
                    ->setHealthNotes($health ?: null)
                    ->setSignedAt(new \DateTimeImmutable())
                    ->setStatus('confirmed');

                if (method_exists($reg, 'setSignatureImage')) {
                    if ($signatureData && str_starts_with($signatureData, 'data:image')) {
                        $reg->setSignatureImage($signatureData);
                    } else {
                        $reg->setSignatureImage(null);
                    }
                }
                if (method_exists($reg, 'setSignatureIp')) {
                    $reg->setSignatureIp($req->getClientIp() ?: null);
                }
                if (method_exists($reg, 'setSignatureUserAgent')) {
                    $reg->setSignatureUserAgent($req->headers->get('User-Agent') ?: null);
                }

                $em->flush();     // valide sous verrou
                $conn->commit();  // libère le verrou

                $this->addFlash('success','Autorisation enregistrée.');
                return $this->redirectToRoute('app_account', [
                    'enfant' => $reg->getChild()->getId(),
                    'tab'    => 'sorties'
                ], 303);

            } catch (\Throwable $e) {
                // Si quelque chose se passe mal, on annule la transaction et on relance l'erreur
                $conn->rollBack();
                throw $e;
            }
        }

        // 👉 Capacité illimitée (pas de transaction/lock nécessaires)
        $reg->setSignatureName($name)
            ->setSignaturePhone($phone)
            ->setHealthNotes($health ?: null)
            ->setSignedAt(new \DateTimeImmutable())
            ->setStatus('confirmed');

        if (method_exists($reg, 'setSignatureImage')) {
            if ($signatureData && str_starts_with($signatureData, 'data:image')) {
                $reg->setSignatureImage($signatureData);
            } else {
                $reg->setSignatureImage(null);
            }
        }
        if (method_exists($reg, 'setSignatureIp')) {
            $reg->setSignatureIp($req->getClientIp() ?: null);
        }
        if (method_exists($reg, 'setSignatureUserAgent')) {
            $reg->setSignatureUserAgent($req->headers->get('User-Agent') ?: null);
        }

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
    private function buildMonthView(
        \DateTimeImmutable $monthStart,
        array $attendances,
        \DateTimeImmutable $syStart,
        \DateTimeImmutable $syEnd
    ): array {
        $tz = new \DateTimeZone('Europe/Paris');

        // Normalise au 1er du mois 00:00
        $monthStart = $monthStart->setTimezone($tz)->modify('first day of this month')->setTime(0, 0, 0);
        $y = (int) $monthStart->format('Y');
        $m = (int) $monthStart->format('m');
        $dim = (int) $monthStart->format('t');

        // Map YYYY-MM-DD -> status (present|absent|late|excused)
        $map = [];
        foreach ($attendances as $a) {
            $map[$a->getDate()->setTimezone($tz)->format('Y-m-d')] = $a->getStatus();
        }

        // Jours du mois
        $days = [];
        for ($d = 1; $d <= $dim; $d++) {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $y, $m, $d), $tz);
            $dow  = (int) $date->format('N'); // 1=lundi .. 7=dimanche
            $isSaturday = ($dow === 6);
            $iso = $date->format('Y-m-d');

            // Samedi : status en base ou 'none' si rien; autres jours : 'off'
            $status = $isSaturday ? ($map[$iso] ?? 'none') : 'off';

            $days[] = [
                'd'          => $d,
                'isSaturday' => $isSaturday,
                'status'     => $status,
            ];
        }

        // Padding avant le 1er du mois (header L M M J V S D)
        // 1=lundi => 0, 2=mardi => 1, ..., 7=dimanche => 6
        $firstDow = (int) $monthStart->format('N'); // 1=lundi..7=dimanche
        $startPad = $firstDow - 1;

        // Navigation mois précédent/suivant, bornée à l’année scolaire
        $prevStart = $monthStart->modify('first day of previous month');
        $nextStart = $monthStart->modify('first day of next month');

        $prevKey = ($prevStart >= $syStart) ? $prevStart->format('Y-m') : null;

        // On compare au 1er jour du mois de la borne supérieure
        $syEndMonthStart = $syEnd->modify('first day of this month')->setTime(0,0,0);
        $nextKey = ($nextStart <= $syEndMonthStart) ? $nextStart->format('Y-m') : null;

        return [
            'label'    => self::frMonthYear($monthStart), // ex: "septembre 2025"
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



    #[Route('/mon-compte/presences/month', name: 'app_account_presences_month', methods: ['GET'])]
    public function presencesMonth(
        Request $req,
        ChildRepository $children,
        AttendanceRepository $attendances
    ): JsonResponse {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $profile = method_exists($user, 'getProfile') ? $user->getProfile() : null;

        $cid = $req->query->getInt('enfant', 0);
        $child = $children->find($cid);
        if (!$child || $child->getParent() !== $profile) {
            return new JsonResponse(['error' => 'Enfant invalide'], 403);
        }

        $askedYear = $req->query->getInt('annee', $this->defaultSchoolStartYear());
        [$syStart, $syEnd] = $this->schoolYearRange($askedYear);

        $tz = new \DateTimeZone('Europe/Paris');
        $mois = (string) $req->query->get('mois', '');
        if ($mois && preg_match('/^\d{4}-\d{2}$/', $mois)) {
            $monthStart = \DateTimeImmutable::createFromFormat('Y-m-d', $mois.'-01', $tz);
        } else {
            $today = new \DateTimeImmutable('today', $tz);
            if ($today < $syStart) $today = $syStart;
            if ($today > $syEnd)   $today = $syEnd;
            $monthStart = $today->modify('first day of this month');
        }

        $monthEnd = $monthStart->modify('last day of this month')->setTime(23,59,59);
        $list = $attendances->findForChild($cid, $monthStart, $monthEnd);
        $cal  = $this->buildMonthView($monthStart, $list, $syStart, $syEnd);

        // Partials : tête + grille (on respecte ton style)
        $head = $this->renderView('account/_calendar_head.html.twig', [
            'current'    => $child,
            'schoolYear' => $askedYear,
            'cal'        => $cal,
        ]);
        $grid = $this->renderView('account/_calendar_grid.html.twig', [
            'cal' => $cal,
        ]);

        return new JsonResponse([
            'label'    => $cal['label'],
            'monthKey' => $cal['monthKey'],
            'prev'     => $cal['prev'],
            'next'     => $cal['next'],
            'head'     => $head,
            'grid'     => $grid,
        ]);
    }




    #[Route('/mon-compte/sorties/{id}/attestation.pdf', name: 'app_account_outing_pdf', methods: ['GET'])]
    public function outingPdf(
        int $id,
        OutingRegistrationRepository $regs,
        PdfGenerator $pdf
    ): Response {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $reg = $regs->find($id);
        if (!$reg || $reg->getChild()->getParent() !== $user->getProfile()) {
            throw $this->createNotFoundException('Inscription introuvable.');
        }
        if (!$reg->getSignedAt()) {
            $this->addFlash('error','Aucune signature enregistrée pour cette inscription.');
            return $this->redirectToRoute('app_account', [
                'enfant' => $reg->getChild()->getId(),
                'tab'    => 'sorties'
            ], 303);
        }

        $binary = $pdf->render('pdf/outing_attestation.html.twig', ['reg' => $reg]);

        if (!$binary || strlen($binary) < 100) {
            if ($this->getParameter('kernel.debug')) {
                return new Response(
                    "Le PDF généré est vide (".strlen((string)$binary)." octets).\n".
                    "Vérifie le template pdf/outing_attestation.html.twig et le service PdfGenerator.",
                    500,
                    ['Content-Type' => 'text/plain; charset=UTF-8']
                );
            }
            throw $this->createNotFoundException('PDF non disponible.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'pdf_');
        file_put_contents($tmp, $binary);

        $filename = 'attestation-sortie-'.$reg->getId().'.pdf';
        return $this->file($tmp, $filename, ResponseHeaderBag::DISPOSITION_ATTACHMENT)
            ->deleteFileAfterSend(true);
    }
}
