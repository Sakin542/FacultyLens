<?php

namespace App\Policies;

use App\Models\DocumentProcessing;
use App\Models\User;

class DocumentPolicy
{
    public function view(User $user, DocumentProcessing $document): bool
    {
        return $user->isAdmin() || $document->user_id === $user->id;
    }

    public function download(User $user, DocumentProcessing $document): bool
    {
        return $user->isAdmin() || $document->user_id === $user->id;
    }

    public function reprocess(User $user, DocumentProcessing $document): bool
    {
        return $user->isAdmin() || $document->user_id === $user->id;
    }

    public function delete(User $user, DocumentProcessing $document): bool
    {
        return $user->isAdmin() || $document->user_id === $user->id;
    }
}

