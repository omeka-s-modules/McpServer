<?php
namespace McpServer\Entity;

use DateTime;
use Omeka\Entity\AbstractEntity;
use Omeka\Entity\User;

/**
 * An MCP key: a random string that signs an MCP request in as its owner.
 *
 * Only its SHA-256 hash is stored, so the key itself is shown once, when it's
 * created, and the stored keys can't be used. Until that page shows it, the
 * new key waits in the session, as a new Omeka API key does. A key works only
 * at /mcp, whose tools only read.
 *
 * @Entity
 */
class McpServerKey extends AbstractEntity
{
    /**
     * @Id
     * @Column(type="integer")
     * @GeneratedValue
     */
    protected $id;

    /**
     * @ManyToOne(targetEntity="Omeka\Entity\User")
     * @JoinColumn(nullable=false, onDelete="CASCADE")
     */
    protected $owner;

    /**
     * @Column(type="string", length=255)
     */
    protected $label;

    /**
     * @Column(type="string", length=64, unique=true)
     */
    protected $hash;

    /**
     * @Column(type="datetime")
     */
    protected $created;

    /**
     * @Column(type="datetime", nullable=true)
     */
    protected $lastUsed;

    /**
     * Make a new key: 32 random bytes, as a URL-safe string.
     */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Get the hash a key is stored and looked up by.
     */
    public static function hashKey(string $key): string
    {
        return hash('sha256', $key);
    }

    public function getId()
    {
        return $this->id;
    }

    public function setOwner(User $owner): void
    {
        $this->owner = $owner;
    }

    public function getOwner(): User
    {
        return $this->owner;
    }

    public function setLabel(string $label): void
    {
        $this->label = $label;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setKey(string $key): void
    {
        $this->hash = self::hashKey($key);
    }

    public function setCreated(DateTime $created): void
    {
        $this->created = $created;
    }

    public function getCreated(): DateTime
    {
        return $this->created;
    }

    public function setLastUsed(DateTime $lastUsed): void
    {
        $this->lastUsed = $lastUsed;
    }

    public function getLastUsed(): ?DateTime
    {
        return $this->lastUsed;
    }
}
