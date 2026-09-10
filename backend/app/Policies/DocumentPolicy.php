<?php

namespace App\Policies;

use App\Models\DocumentProcessing;
use App\Models\User;

class DocumentPolicy
{
    use ResolvesCourseAccess;

    public function view(User $user, DocumentProcessing $document): bool
    {
        return $document->user_id === $user->id || $this->allows($user, $document->course, 'view_documents');
    }

    public function download(User $user, DocumentProcessing $document): bool
    {
        return $document->user_id === $user->id || $this->allows($user, $document->course, 'download_documents');
    }

    public function reprocess(User $user, DocumentProcessing $document): bool
    {
        return $document->user_id === $user->id || $this->allows($user, $document->course, 'manage_documents');
    }

    public function delete(User $user, DocumentProcessing $document): bool
    {
        return $document->user_id === $user->id || $this->allows($user, $document->course, 'manage_documents');
    }
}
