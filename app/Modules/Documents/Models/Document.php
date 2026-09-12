<?php

declare(strict_types=1);

namespace App\Modules\Documents\Models;

use App\Core\Traits\BelongsToOrganization;
use App\Core\Traits\HasExternalIdentity;
use App\Modules\Connector\Models\ImmowareConnection;
use App\Modules\Contacts\Models\Contact;
use App\Modules\Estate\Models\CaseFile;
use App\Modules\Estate\Models\Property;
use App\Modules\Estate\Models\Unit;
use App\Modules\Sync\Models\WriteOperation;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[UseFactory(DocumentFactory::class)]
class Document extends Model
{
    use BelongsToOrganization, HasExternalIdentity, HasFactory;

    protected $table = 'documents';

    /** @var array<int, string> */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'remote_last_modified' => 'immutable_datetime',
            'content_stored' => 'boolean',
        ];
    }

    public static function hashPath(string $path): string
    {
        return hash('sha256', \Normalizer::normalize($path, \Normalizer::FORM_C) ?: $path);
    }

    /**
     * @return BelongsTo<ImmowareConnection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ImmowareConnection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<DocumentFolder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'unit_id');
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * @return BelongsTo<CaseFile, $this>
     */
    public function case(): BelongsTo
    {
        return $this->belongsTo(CaseFile::class, 'case_id');
    }

    /**
     * @return BelongsTo<WriteOperation, $this>
     */
    public function writeOperation(): BelongsTo
    {
        return $this->belongsTo(WriteOperation::class, 'write_operation_id');
    }
}
