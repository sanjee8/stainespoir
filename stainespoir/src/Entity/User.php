<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity('email', message: 'Cet email est déjà utilisé.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;

    #[ORM\Column(length:180, unique:true)]
    private ?string $email = null;

    #[ORM\Column(type:'json')]
    private array $roles = [];

    #[ORM\Column] private string $password = '';

    #[ORM\OneToOne(mappedBy: 'user', targetEntity: ParentProfile::class, cascade: ['persist', 'remove'])]
    private ?ParentProfile $profile = null;

    #[ORM\Column(options: ['default' => 'CURRENT_TIMESTAMP'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'boolean')]
    private bool $isApproved = false;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $approvedAt = null;

    public function isApproved(): bool { return $this->isApproved; }
    public function setIsApproved(bool $v): self { $this->isApproved = $v; return $this; }

    public function getApprovedAt(): ?\DateTimeInterface { return $this->approvedAt; }
    public function setApprovedAt(?\DateTimeInterface $d): self { $this->approvedAt = $d; return $this; }

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->roles = ['ROLE_PARENT'];
    }

    public function getFullName(): string
    {
        $p = method_exists($this, 'getProfile') ? $this->getProfile() : null;
        $first = $p?->getFirstName() ?? '';
        $last  = $p?->getLastName() ?? '';
        return trim($first.' '.$last);
    }

    public function getId(): ?int { return $this->id; }
    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): self { $this->email = strtolower($email); return $this; }

    public function getUserIdentifier(): string { return (string)$this->email; }

    /** @return string[] */
    public function getRoles(): array { return array_values(array_unique($this->roles)); }
    public function setRoles(array $roles): self { $this->roles = $roles; return $this; }

    public function getPassword(): string { return $this->password; }
    public function setPassword(string $password): self { $this->password = $password; return $this; }

    public function eraseCredentials(): void {}

    public function getProfile(): ?ParentProfile { return $this->profile; }
    public function setProfile(?ParentProfile $profile): self
    {
        $this->profile = $profile;
        if ($profile && $profile->getUser() !== $this) $profile->setUser($this);
        return $this;
    }
}
