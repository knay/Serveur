<?php
namespace Imerir\NoyauBundle\Entity;
use FOS\UserBundle\Entity\User as BaseUser;
use FR3D\LdapBundle\Model\LdapUserInterface as LdapUserInterface;
use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity
 * @ORM\Table(name="user")
 */
class Utilisateur extends BaseUser 
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    protected $name;

    public function __construct()
    {
       parent::__construct();
       if (empty($this->roles)) {
         $this->roles[] = 'ROLE_USER';
       }
    }
    
    public function setName($name) {
        $this->name = $name;
    }
}