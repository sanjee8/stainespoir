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
