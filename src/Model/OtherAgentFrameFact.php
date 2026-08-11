<?php

declare(strict_types=1);

namespace NaviBrain\Model;

use Divergence\Models\ActiveRecord;
use Divergence\Models\Getters;
use Divergence\Models\Mapping\Column;

/** A durable, corrigible proposition about the one authorized other agent. */
final class OtherAgentFrameFact extends ActiveRecord
{
    use Getters;

    public static $tableName = 'other_agent_frame_facts';
    public static $primaryKey = 'id';

    public static $indexes = [
        'other_agent_frame_key' => ['fields' => ['actor', 'fact_key', 'status']],
        'other_agent_frame_provenance' => ['fields' => ['provenance_kind', 'provenance_id']],
        'other_agent_frame_expiry' => ['fields' => ['status', 'expires_at']],
    ];

    #[Column(type: 'integer', primary: true, autoincrement: true, unsigned: true)]
    protected ?int $id = null;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $created_at;

    #[Column(type: 'timestamp', default: 'CURRENT_TIMESTAMP')]
    protected $updated_at;

    #[Column(type: 'string', length: 32)]
    protected string $actor = 'primary_user';

    #[Column(type: 'integer', unsigned: true)]
    protected int $recursion_order = 1;

    #[Column(type: 'string', length: 96)]
    protected string $fact_key;

    #[Column(type: 'clob')]
    protected string $proposition;

    #[Column(type: 'serialized')]
    protected array $structured = [];

    #[Column(type: 'decimal', precision: 5, scale: 4)]
    protected float $confidence = 0.0;

    #[Column(type: 'enum', values: ['reported', 'authorized_observation', 'inferred'])]
    protected string $knowledge_access;

    #[Column(type: 'enum', values: ['stated', 'inferred'])]
    protected string $representation;

    #[Column(type: 'enum', values: ['user_correction', 'sense_reading', 'utterance_outcome', 'deterministic_inference'])]
    protected string $provenance_kind;

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $provenance_id = null;

    #[Column(type: 'serialized')]
    protected array $evidence = [];

    #[Column(type: 'integer', notnull: false, unsigned: true)]
    protected ?int $supersedes_id = null;

    #[Column(type: 'enum', values: ['active', 'superseded', 'corrected', 'rejected', 'expired'])]
    protected string $status = 'active';

    #[Column(type: 'timestamp', notnull: false)]
    protected $expires_at;
}
