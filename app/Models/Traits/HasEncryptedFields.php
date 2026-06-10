<?php

namespace App\Models\Traits;

use Illuminate\Support\Facades\DB;

trait HasEncryptedFields
{
    // Declare in the model:
    //   protected array $encryptedFields = ['message', 'dl_number'];

    public function setAttribute($key, $value): mixed
    {
        if (in_array($key, $this->encryptedFields ?? [], true) && $value !== null) {
            $encKey = config("encryption_keys.{$this->getConnectionName()}");
            // encode(..., 'base64') converts bytea → TEXT so PDO can store it in a TEXT column
            $row    = DB::connection($this->getConnectionName())
                        ->selectOne("SELECT encode(pgp_sym_encrypt(?, ?), 'base64') AS enc", [$value, $encKey]);
            return parent::setAttribute($key, $row->enc);
        }

        return parent::setAttribute($key, $value);
    }

    public function getAttribute($key): mixed
    {
        $value = parent::getAttribute($key);

        if (in_array($key, $this->encryptedFields ?? [], true) && $value !== null) {
            $encKey = config("encryption_keys.{$this->getConnectionName()}");
            // decode(..., 'base64') reverses the encode step before decrypting
            $row    = DB::connection($this->getConnectionName())
                        ->selectOne("SELECT pgp_sym_decrypt(decode(?, 'base64'), ?) AS dec", [$value, $encKey]);
            return $row?->dec;
        }

        return $value;
    }
}
