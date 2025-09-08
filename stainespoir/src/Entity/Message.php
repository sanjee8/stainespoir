<?php
namespace App\Entity;

use App\Repository\MessageRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MessageRepository::class)]
#[ORM\Table(name: 'message')]
class Message
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column] private ?int $id = null;

    #[ORM\ManyToOne] #[ORM\JoinColumn(nullable:false, onDelete:'CASCADE')]
    private ?Child $child = null;

    #[ORM\Column(length:160)] private string $subject = '';
    #[ORM\Column(type:'text')] private string $body = '';

    // 'staff' ou 'parent' (pour l'historique)
    #[ORM\Column(length:16)] private string $sender = 'staff';

    #[ORM\Column(type:'datetime_immutable')] private \DateTimeImmutable $createdAt;
    #[ORM\Column(type:'datetime_immutable', nullable:true)] private ?\DateTimeImmutable $readAt = null;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId():?int {return $this->id;}
    public function getChild():?Child {return $this->child;}
    public function setChild(Child $c):self {$this->child=$c;return $this;}

    public function getSubject():string {return $this->subject;}
    public function setSubject(string $s):self {$this->subject=$s;return $this;}

    public function getBody():string {return $this->body;}
    public function setBody(string $b):self {$this->body=$b;return $this;}

    public function getSender():string {return $this->sender;}
    public function setSender(string $s):self {$this->sender=$s;return $this;}

    public function getCreatedAt():\DateTimeImmutable {return $this->createdAt;}
    public function getReadAt():?\DateTimeImmutable {return $this->readAt;}
    public function markRead():self {$this->readAt = new \DateTimeImmutable(); return $this;}
}
