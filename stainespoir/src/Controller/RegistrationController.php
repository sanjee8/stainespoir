<?php

namespace App\Controller;

use App\Entity\User;
use App\Entity\ParentProfile;
use App\Entity\Child;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validation;
use App\Repository\UserRepository;

final class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register', methods: ['GET'])]
    public function form(CsrfTokenManagerInterface $csrf): Response
    {
        return $this->render('auth/register.html.twig', [
            'csrf_token' => $csrf->getToken('register_form')->getValue(),
            // tu peux aussi passer des listes (niveaux, etc.)
        ]);
    }

    #[Route('/inscription', name: 'app_register_submit', methods: ['POST'])]
    public function submit(
        Request $request,
        EntityManagerInterface $em,
        UserRepository $users,
        UserPasswordHasherInterface $hasher,
        CsrfTokenManagerInterface $csrf
    ): JsonResponse {
        $payload = json_decode($request->getContent() ?? '[]', true);
        if (!is_array($payload)) $payload = [];

        // CSRF
        $token = (string)($payload['_token'] ?? '');
        if (!$csrf->isTokenValid(new CsrfToken('register_form', $token))) {
            return new JsonResponse(['ok'=>false, 'message'=>'Jeton CSRF invalide'], 419);
        }

        // ---- Validation basique
        $email = trim((string)($payload['account']['email'] ?? ''));
        $password = (string)($payload['account']['password'] ?? '');
        $confirm = (string)($payload['account']['confirm'] ?? '');

        $parent  = (array)($payload['parent'] ?? []);
        $kids    = (array)($payload['kids'] ?? []);
        $consents= (array)($payload['consents'] ?? []);

        $errors = [];

        // email
        $validator = Validation::createValidator();
        $viol = $validator->validate($email, [new Assert\NotBlank(), new Assert\Email()]);
        if (count($viol) > 0) $errors['account.email'] = 'Email invalide.';
        if ($users->findOneBy(['email' => strtolower($email)])) $errors['account.email'] = 'Email déjà utilisé.';

        // password
        if (strlen($password) < 8) $errors['account.password'] = 'Mot de passe trop court.';
        if (!preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
            $errors['account.password'] = 'Requis : 1 majuscule, 1 chiffre, 1 symbole.';
        }
        if ($password !== $confirm) $errors['account.confirm'] = 'Les mots de passe ne correspondent pas.';

        // parent
        if (empty(trim((string)($parent['firstName'] ?? '')))) $errors['parent.firstName'] = 'Prénom requis.';
        if (empty(trim((string)($parent['lastName'] ?? ''))))  $errors['parent.lastName']  = 'Nom requis.';
        if (empty(trim((string)($parent['phone'] ?? ''))))     $errors['parent.phone']     = 'Téléphone requis.';
        if (empty(trim((string)($parent['relation'] ?? ''))))  $errors['parent.relation']  = 'Lien requis.';

        // kids
        if (count($kids) < 1) $errors['kids'] = 'Ajoutez au moins un enfant.';
        foreach ($kids as $i => $k) {
            if (empty(trim((string)($k['firstName'] ?? '')))) $errors["kids.$i.firstName"] = 'Prénom requis.';
            if (empty(trim((string)($k['lastName'] ?? ''))))  $errors["kids.$i.lastName"]  = 'Nom requis.';
            if (empty(trim((string)($k['level'] ?? ''))))     $errors["kids.$i.level"]     = 'Niveau requis.';
        }

        // consents
        if (!($consents['rgpd'] ?? false)) $errors['consents.rgpd'] = 'Consentement RGPD requis.';

        if ($errors) return new JsonResponse(['ok'=>false, 'errors'=>$errors], 422);

        // ---- Création
        $user = (new User())
            ->setEmail($email)
            ->setPassword($hasher->hashPassword(new User(), $password)); // on hashe sur un User vierge ou $user après setEmail

        $profile = (new ParentProfile())
            ->setUser($user)
            ->setFirstName(trim((string)$parent['firstName']))
            ->setLastName(trim((string)$parent['lastName']))
            ->setPhone(trim((string)$parent['phone']))
            ->setRelationToChild(trim((string)$parent['relation']))
            ->setAddress($parent['address'] ?? null)
            ->setPostalCode($parent['postalCode'] ?? null)
            ->setCity($parent['city'] ?? null)
            ->setPhotoConsent((bool)($consents['photo'] ?? false))
            ->setRgpdConsentAt(new \DateTimeImmutable());

        $user->setProfile($profile);
        $em->persist($user);
        $em->persist($profile);

        foreach ($kids as $k) {
            $child = (new Child())
                ->setParent($profile)
                ->setFirstName(trim((string)($k['firstName'] ?? '')))
                ->setLastName(trim((string)($k['lastName'] ?? '')))
                ->setLevel((string)($k['level'] ?? ''))
                ->setSchool($k['school'] ?? null)
                ->setNotes($k['notes'] ?? null);
            $dob = $k['dob'] ?? null;
            if ($dob) { try { $child->setDateOfBirth(new \DateTime($dob)); } catch (\Exception $e) {} }
            $em->persist($child);
        }

        $em->flush();

        return new JsonResponse(['ok'=>true], 201);
    }
}
