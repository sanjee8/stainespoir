<?php
// src/DataFixtures/AppFixtures.php
namespace App\DataFixtures;

use App\Entity\User;
use App\Entity\ParentProfile;          // adapte si ton entité s'appelle autrement
use App\Entity\Child;                  // idem si ton entité enfant a un autre nom
use App\Entity\Attendance;
use App\Entity\Message;
use App\Entity\Outing;
use App\Entity\OutingRegistration;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory as Faker;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AppFixtures extends Fixture
{
    public function __construct(private readonly UserPasswordHasherInterface $hasher) {}

    public function load(ObjectManager $em): void
    {
        $faker = Faker::create('fr_FR');
        $now   = new \DateTimeImmutable();

        // ============================================================
        // 1) UTILISATEUR PARENT DE DEMO + PROFIL (+ RGPD)
        // ============================================================
        $user = new User();
        $this->setUserEmail($user, 'parent.demo@stains-espoir.fr');
        $this->setUserRoles($user, ['ROLE_PARENT']);
        $this->setUserPassword($user, $this->hasher->hashPassword($user, 'Passw0rd!'));
        $this->setUserRgpdConsentAt($user, $now); // si le champ est sur User

        $profile = new ParentProfile();
        $this->setProfileFirstName($profile, 'Nadia');
        $this->setProfileLastName($profile, 'Ben Ali');
        $this->setProfilePhone($profile, '06 25 70 29 90');
        $this->setProfileRgpdConsentAt($profile, $now); // si le champ est sur Profile
        $this->setProfilePhotoConsent($profile, true);  // si bool not null
        $this->setProfilePhotoConsentAt($profile, $now); // si timestamp not null
        $this->linkProfileToUser($profile, $user);

        $em->persist($user);
        $em->persist($profile);


        // ============================================================
        // 5) SORTIES (3 à venir + 2 passées) + INSCRIPTIONS ENFANTS
        // ============================================================
        $upcoming = self::nextSaturdays(3, '10:00');     // DateTime (mutable)
        $past     = self::previousSaturdays(2, '10:00'); // DateTime (mutable)

        $outings = [];
        foreach ($upcoming as $i => $dt) {
            $o = new Outing();
            $this->setOutingTitle($o, 'Sortie n°'.($i+1).' — Atelier / Match');
            $this->setOutingStartsAt($o, \DateTimeImmutable::createFromMutable($dt));
            $this->setOutingLocation($o, $faker->randomElement(['Gymnase Joliot-Curie', 'Parc départemental', 'Maison des Assos']));
            $this->setOutingDescription($o, $faker->sentence(12));
            $em->persist($o);
            $outings[] = $o;
        }
        foreach ($past as $i => $dt) {
            $o = new Outing();
            $this->setOutingTitle($o, 'Sortie passée n°'.($i+1));
            $this->setOutingStartsAt($o, \DateTimeImmutable::createFromMutable($dt));
            $this->setOutingLocation($o, $faker->randomElement(['Gymnase Joliot-Curie', 'Parc départemental', 'Maison des Assos']));
            $this->setOutingDescription($o, $faker->sentence(12));
            $em->persist($o);
            $outings[] = $o;
        }

        // ============================================================
        // FLUSH
        // ============================================================
        $em->flush();

        if (\PHP_SAPI === 'cli') {
            echo PHP_EOL.'Comptes de démo:' . PHP_EOL;
            echo ' - parent.demo@stains-espoir.fr / Passw0rd!' . PHP_EOL;
        }
    }

    // ----------------------------------------------------------------
    // Helpers "tolérants" (essaient plusieurs noms de setters)
    // ----------------------------------------------------------------

    /** -------- User -------- */
    private function setUserEmail(object $user, string $email): void {
        foreach (['setEmail','setUsername'] as $m) if (method_exists($user, $m)) { $user->$m($email); return; }
    }
    private function setUserPassword(object $user, string $hash): void {
        foreach (['setPassword','setHashedPassword'] as $m) if (method_exists($user, $m)) { $user->$m($hash); return; }
    }
    private function setUserRoles(object $user, array $roles): void {
        foreach (['setRoles'] as $m) if (method_exists($user, $m)) { $user->$m($roles); return; }
    }
    private function setUserRgpdConsentAt(object $user, \DateTimeImmutable $dt): void {
        foreach (['setRgpdConsentAt','setConsentRgpdAt','setRgpdAt','setRgpdAcceptedAt','setRgpdAcceptedOn'] as $m) {
            if (method_exists($user, $m)) { $user->$m($dt); return; }
        }
        // pas d'alerte : le champ peut être porté par Profile seulement
    }

    /** -------- ParentProfile -------- */
    private function linkProfileToUser(object $profile, object $user): void {
        foreach (['setUser','setAccount','setOwner'] as $m) if (method_exists($profile, $m)) { $profile->$m($user); return; }
    }
    private function setProfileFirstName(object $p, string $v): void {
        foreach (['setFirstName','setPrenom'] as $m) if (method_exists($p, $m)) { $p->$m($v); return; }
    }
    private function setProfileLastName(object $p, string $v): void {
        foreach (['setLastName','setNom'] as $m) if (method_exists($p, $m)) { $p->$m($v); return; }
    }
    private function setProfilePhone(object $p, string $v): void {
        foreach (['setPhone','setTelephone','setTel'] as $m) if (method_exists($p, $m)) { $p->$m($v); return; }
    }
    private function setProfileRgpdConsentAt(object $p, \DateTimeImmutable $dt): void {
        foreach (['setRgpdConsentAt','setConsentRgpdAt','setRgpdAt','setRgpdAcceptedAt','setRgpdAcceptedOn'] as $m) {
            if (method_exists($p, $m)) { $p->$m($dt); return; }
        }
        // si le champ n'est pas sur Profile, il est peut-être sur User (déjà traité)
    }
    private function setProfilePhotoConsent(object $p, bool $v): void {
        foreach (['setPhotoConsent','setConsentPhoto','setAutorisationPhoto','setPhotoOk'] as $m) {
            if (method_exists($p, $m)) { $p->$m($v); return; }
        }
    }
    private function setProfilePhotoConsentAt(object $p, \DateTimeImmutable $dt): void {
        foreach (['setPhotoConsentAt','setConsentPhotoAt','setPhotoAutoriseAt'] as $m) {
            if (method_exists($p, $m)) { $p->$m($dt); return; }
        }
    }

    /** -------- Child -------- */
    private function setChildFirstName(object $c, string $v): void {
        foreach (['setFirstName','setPrenom'] as $m) if (method_exists($c, $m)) { $c->$m($v); return; }
    }
    private function setChildLastName(object $c, string $v): void {
        foreach (['setLastName','setNom'] as $m) if (method_exists($c, $m)) { $c->$m($v); return; }
    }
    private function setChildDob(object $c, \DateTimeImmutable $dt): void {
        foreach (['setDob','setBirthDate','setDateOfBirth','setBirthday','setDateNaissance'] as $m) {
            if (method_exists($c, $m)) {
                try { $c->$m($dt); } catch (\TypeError) { $c->$m(new \DateTime($dt->format('Y-m-d'))); }
                return;
            }
        }
        $this->warn('DOB', $c);
    }
    private function setChildLevel(object $c, string $v): void {
        foreach (['setLevel','setNiveau','setGrade','setClassLevel'] as $m) if (method_exists($c, $m)) { $c->$m($v); return; }
        $this->warn('Level', $c);
    }
    private function setChildSchool(object $c, ?string $v): void {
        foreach (['setSchool','setEtablissement','setEcole','setSchoolName'] as $m) if (method_exists($c, $m)) { $c->$m($v); return; }
    }
    private function linkChildToParent(object $c, object $p): void {
        foreach (['setParent','setParentProfile','setTuteur','setGuardian','setProfile'] as $m) if (method_exists($c, $m)) { $c->$m($p); return; }
        $this->warn('Parent link', $c);
    }

    /** -------- Attendance -------- */
    private function setAttendanceChild(object $a, object $child): void {
        foreach (['setChild','setEnfant'] as $m) if (method_exists($a, $m)) { $a->$m($child); return; }
    }
    private function setAttendanceDate(object $a, \DateTimeInterface $d): void {
        foreach (['setDate','setDay','setJour'] as $m) if (method_exists($a, $m)) { $a->$m($d); return; }
    }
    private function setAttendanceStatus(object $a, string $s): void {
        foreach (['setStatus','setStatut'] as $m) if (method_exists($a, $m)) { $a->$m($s); return; }
    }
    private function setAttendanceNotes(object $a, ?string $n): void {
        foreach (['setNotes','setComment','setCommentaires'] as $m) if (method_exists($a, $m)) { $a->$m($n); return; }
    }

    /** -------- Message -------- */
    private function setMessageChild(object $m, object $child): void {
        foreach (['setChild','setEnfant'] as $mm) if (method_exists($m, $mm)) { $m->$mm($child); return; }
    }
    private function setMessageSubject(object $m, string $s): void {
        foreach (['setSubject','setTitre'] as $mm) if (method_exists($m, $mm)) { $m->$mm($s); return; }
    }
    private function setMessageBody(object $m, string $b): void {
        foreach (['setBody','setContent','setContenu','setMessage'] as $mm) if (method_exists($m, $mm)) { $m->$mm($b); return; }
    }
    private function setMessageSender(object $m, string $s): void {
        foreach (['setSender','setFrom','setExpediteur'] as $mm) if (method_exists($m, $mm)) { $m->$mm($s); return; }
    }
    private function maybeSetMessageCreatedAt(object $m, \DateTimeImmutable $dt): void {
        foreach (['setCreatedAt','setDate'] as $mm) if (method_exists($m, $mm)) { $m->$mm($dt); return; }
    }

    /** -------- Outing -------- */
    private function setOutingTitle(object $o, string $t): void {
        foreach (['setTitle','setTitre'] as $mm) if (method_exists($o, $mm)) { $o->$mm($t); return; }
    }
    private function setOutingStartsAt(object $o, \DateTimeImmutable $dt): void {
        foreach (['setStartsAt','setStartAt','setDate'] as $mm) if (method_exists($o, $mm)) { $o->$mm($dt); return; }
    }
    private function getOutingStartsAt(object $o): \DateTimeImmutable {
        foreach (['getStartsAt','getStartAt','getDate'] as $mm) if (method_exists($o, $mm)) {
            $v = $o->$mm(); if ($v instanceof \DateTimeInterface) return \DateTimeImmutable::createFromInterface($v);
        }
        return new \DateTimeImmutable('@0');
    }
    private function setOutingLocation(object $o, ?string $loc): void {
        foreach (['setLocation','setLieu'] as $mm) if (method_exists($o, $mm)) { $o->$mm($loc); return; }
    }
    private function setOutingDescription(object $o, ?string $d): void {
        foreach (['setDescription','setDesc'] as $mm) if (method_exists($o, $mm)) { $o->$mm($d); return; }
    }

    /** -------- OutingRegistration -------- */
    private function setOutRegChild(object $r, object $child): void {
        foreach (['setChild','setEnfant'] as $mm) if (method_exists($r, $mm)) { $r->$mm($child); return; }
    }
    private function setOutRegOuting(object $r, object $o): void {
        foreach (['setOuting','setSortie'] as $mm) if (method_exists($r, $mm)) { $r->$mm($o); return; }
    }
    private function setOutRegStatus(object $r, string $s): void {
        foreach (['setStatus','setStatut'] as $mm) if (method_exists($r, $mm)) { $r->$mm($s); return; }
    }
    private function setOutRegNotes(object $r, ?string $n): void {
        foreach (['setNotes','setComment','setCommentaires'] as $mm) if (method_exists($r, $mm)) { $r->$mm($n); return; }
    }

    private function warn(string $what, object $entity): void
    {
        if (\PHP_SAPI === 'cli') {
            fwrite(STDERR, "[fixtures] Setter {$what} introuvable pour ".get_class($entity).PHP_EOL);
        }
    }

    // ----------------------------------------------------------------
    // Générateurs de dates + tirage pondéré
    // ----------------------------------------------------------------

    /** 12 (count) derniers samedis (DateTimeImmutable, minuit) */
    private static function lastSaturdays(int $count): array
    {
        $out = [];
        $d = new \DateTimeImmutable('last saturday');
        for ($i = 0; $i < $count; $i++) {
            $out[] = $d->modify("-{$i} week");
        }
        return array_reverse($out);
    }

    /** N prochains samedis à HH:MM (DateTime mutable) */
    private static function nextSaturdays(int $count, string $time = '10:00'): array
    {
        $out = [];
        $d = new \DateTime('next saturday '.$time);
        for ($i = 0; $i < $count; $i++) {
            $out[] = (clone $d)->modify("+{$i} week");
        }
        return $out;
    }

    /** N samedis passés à HH:MM (DateTime mutable) */
    private static function previousSaturdays(int $count, string $time = '10:00'): array
    {
        $out = [];
        $d = new \DateTime('last saturday '.$time);
        for ($i = 0; $i < $count; $i++) {
            $out[] = (clone $d)->modify("-{$i} week");
        }
        return $out;
    }

    /** Tirage pondéré: ['val'=>poids, ...] -> string */
    private static function weightedPick(array $weights): string
    {
        $sum = array_sum($weights);
        $r = mt_rand(1, max(1, $sum));
        foreach ($weights as $k => $w) {
            if (($r -= $w) <= 0) return $k;
        }
        return array_key_first($weights);
    }
}
