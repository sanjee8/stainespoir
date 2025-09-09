<?php
namespace App\Entity;

use App\Repository\OutingRegistrationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OutingRegistrationRepository::class)]
#[ORM\Table(name: 'outing_registration')]
#[ORM\UniqueConstraint(columns: ['child_id','outing_id'])]
class OutingRegistration
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false, onDelete:'CASCADE')]
    private ?Child $child = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false, onDelete:'CASCADE')]
    private ?Outing $outing = null;

    // invited | confirmed | declined | attended | absent
    #[ORM\Column(length:16)] private string $status = 'invited';

    #[ORM\Column(type:'text', nullable:true)] private ?string $notes = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $signedAt = null;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $signatureName = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $signaturePhone = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $healthNotes = null;


    public function getSignedAt(): ?\DateTimeImmutable { return $this->signedAt; }
    public function setSignedAt(?\DateTimeImmutable $dt): self { $this->signedAt = $dt; return $this; }

    public function getSignatureName(): ?string { return $this->signatureName; }
    public function setSignatureName(?string $v): self { $this->signatureName = $v; return $this; }

    public function getSignaturePhone(): ?string { return $this->signaturePhone; }
    public function setSignaturePhone(?string $v): self { $this->signaturePhone = $v; return $this; }

    public function getHealthNotes(): ?string { return $this->healthNotes; }
    public function setHealthNotes(?string $v): self { $this->healthNotes = $v; return $this; }

    public function getId():?int {return $this->id;}
    public function getChild():?Child {return $this->child;}
    public function setChild(Child $c):self {$this->child=$c;return $this;}
    public function getOuting():?Outing {return $this->outing;}
    public function setOuting(Outing $o):self {$this->outing=$o;return $this;}
    public function getStatus():string {return $this->status;}
    public function setStatus(string $s):self {$this->status=$s;return $this;}
    public function getNotes():?string {return $this->notes;}
    public function setNotes(?string $n):self {$this->notes=$n;return $this;}
}
