<?php

namespace App\Entity;

use App\Repository\ChildRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ChildRepository::class)]
#[ORM\Table(name:'child')]
class Child
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false, onDelete:'CASCADE')]
    private ?ParentProfile $parent = null;

    #[ORM\Column(type: 'boolean')]
    private bool $isApproved = false;

    public function isApproved(): bool { return $this->isApproved; }
    public function setIsApproved(bool $v): self { $this->isApproved = $v; return $this; }

    #[ORM\Column(length:100)] private string $firstName = '';
    #[ORM\Column(length:100)] private string $lastName  = '';
    #[ORM\Column(type:'date', nullable:true)] private ?\DateTimeInterface $dateOfBirth = null;

    #[ORM\Column(length:30)] private string $level = '';   // CE2, CM1, ...
    #[ORM\Column(length:255, nullable:true)] private ?string $school = null;
    #[ORM\Column(type:'text', nullable:true)] private ?string $notes = null;

    public function getId(): ?int { return $this->id; }
    public function getParent(): ?ParentProfile { return $this->parent; }
    public function setParent(ParentProfile $p): self { $this->parent = $p; return $this; }

    public function getFirstName(): string { return $this->firstName; }
    public function setFirstName(string $v): self { $this->firstName = $v; return $this; }
    public function getLastName(): string { return $this->lastName; }
    public function setLastName(string $v): self { $this->lastName = $v; return $this; }

    public function getDateOfBirth(): ?\DateTimeInterface { return $this->dateOfBirth; }
    public function setDateOfBirth(?\DateTimeInterface $d): self { $this->dateOfBirth = $d; return $this; }

    public function getLevel(): string { return $this->level; }
    public function setLevel(string $v): self { $this->level = $v; return $this; }

    public function getSchool(): ?string { return $this->school; }
    public function setSchool(?string $v): self { $this->school = $v; return $this; }

    public function getNotes(): ?string { return $this->notes; }
    public function setNotes(?string $v): self { $this->notes = $v; return $this; }
}
