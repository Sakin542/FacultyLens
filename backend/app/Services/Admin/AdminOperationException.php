<?php

namespace App\Services\Admin;

use RuntimeException;

/** An admin operation failure whose message is safe to show in the task center. */
class AdminOperationException extends RuntimeException {}
