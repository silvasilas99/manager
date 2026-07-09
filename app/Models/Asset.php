<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use SoftDeletes;

    public const NAME = "asset";
    protected $table = "assets";
    protected $primaryKey = "id";

    protected $fillable = [
        "id",
        "patient_id",
        "category_id",
        "ds_id",
        "zkp_proof",
        "encrypt_algoritm",
        "bytes",
        "active",
        "created_at",
        "created_by",
        "updated_at",
        "updated_by",
        "deleted_at",
        "deleted_by"
    ];
}
