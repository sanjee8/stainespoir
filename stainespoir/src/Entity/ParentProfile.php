<?php

namespace App\Entity;

use App\Repository\ParentProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use App\Entity\Child;

#[ORM\Entity(repositoryClass: ParentProfileRepository::class)]
#[ORM\Table(name:'parent_profile')]
class ParentProfile
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;

    #[ORM\OneToOne(inversedBy:'profile')]
    #[ORM\JoinColumn(nullable:false, onDelete:'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $relation = null;

    public function getRelation(): ?string
    {
        return $this->relation;
    }

    public function setRelation(?string $relation): self
    {
        $this->relation = $relation;
        return $this;
    }

    #[ORM\Column(length:100)] #[Assert\NotBlank] private string $firstName = '';
    #[ORM\Column(length:100)] #[Assert\NotBlank] private string $lastName  = '';
    #[ORM\Column(length:30)]  #[Assert\NotBlank] private string $phone     = '';
    #[ORM\Column(length:50)]  private string $relationToChild = ''; // Mère, Père, Tuteur…

    #[ORM\Column(length:255, nullable:true)] private ?string $address = null;
    #[ORM\Column(length:16,  nullable:true)] private ?string $postalCode = null;
    #[ORM\Column(length:100, nullable:true)] private ?string $city = null;

    #[ORM\Column(type:'datetime_immutable')] private \DateTimeImmutable $rgpdConsentAt;
    #[ORM\Column(type:'boolean', options:['default'=>false])] private bool $photoConsent = false;

    /** @var Collection<int, Child> */
    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: Child::class, cascade: ['persist'], orphanRemoval: false)]
    private Collection $children;

    public function getRgpdConsentAt(): ?\DateTimeImmutable { return $this->rgpdConsentAt; }
    public function setRgpdConsentAt(?\DateTimeImmutable $dt): self { $this->rgpdConsentAt = $dt; return $this; }

    #[ORM\PrePersist]
    public function initConsents(): void
    {
        if ($this->rgpdConsentAt === null) {
            // stocke en heure locale (évite les surprises)
            $this->rgpdConsentAt = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        }
    }
    public function __construct()
    {
        $this->children = new ArrayCollection();
    }

    /** @return Collection<int, Child> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(Child $child): self
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            if (method_exists($child, 'setParent')) {
                $child->setParent($this);
            }
        }
        return $this;
    }



    public function removeChild(Child $child): self
    {
        if ($this->children->removeElement($child)) {
            if (method_exists($child, 'getParent') && $child->getParent() === $this) {
                if (method_exists($child, 'setParent')) {
                    $child->setParent(null);
                }
            }
        }
        return $this;
    }

    public function getId(): ?int { return $this->id; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(User $user): self { $this->user = $user; return $this; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): self { $this->firstName = $v; return $this; }

    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): self { $this->lastName = $v; return $this; }

    public function getPhone(): string { return $this->phone; }
    public function setPhone(string $v): self { $this->phone = $v; return $this; }

    public function getRelationToChild(): string { return $this->relationToChild; }
    public function setRelationToChild(string $v): self { $this->relationToChild = $v; return $this; }

    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $v): self { $this->address = $v; return $this; }

    public function getPostalCode(): ?string { return $this->postalCode; }
    public function setPostalCode(?string $v): self { $this->postalCode = $v; return $this; }

    public function getCity(): ?string { return $this->city; }
    public function setCity(?string $v): self { $this->city = $v; return $this; }

    public function getPhotoConsent(): bool { return $this->photoConsent; }
    public function setPhotoConsent(bool $b): self { $this->photoConsent = $b; return $this; }

    public function __toString(): string
    {
        $first = method_exists($this, 'getFirstName') ? ($this->getFirstName() ?? '') : '';
        $last  = method_exists($this, 'getLastName')  ? ($this->getLastName()  ?? '') : '';
        $name  = trim($first.' '.$last);

        if ($name !== '') {
            return $name;
        }

        $email = method_exists($this, 'getUser') ? ($this->getUser()?->getEmail() ?? '') : '';
        return $email !== '' ? $email : 'Parent #'.((string)($this->getId() ?? ''));
    }

}
