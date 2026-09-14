<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadProfilePictureRequest;
use App\Models\User;
use App\Services\Profile\ProfilePictureException;
use App\Services\Profile\ProfilePictureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Faculty profile picture endpoints. The target account is ALWAYS the authenticated user for
 * mutations; a user id in the payload is never read. Images are streamed from private storage.
 */
class ProfilePictureController extends Controller
{
    public function __construct(protected ProfilePictureService $pictures)
    {
    }

    /** POST /api/profile/picture (multipart, field: profile_picture) */
    public function store(UploadProfilePictureRequest $request): JsonResponse
    {
        $user = $request->user();
        $hadPicture = $user->hasProfilePicture();

        try {
            $user = $this->pictures->upload($user, $request->file('profile_picture'));
        } catch (ProfilePictureException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
                'errors' => $e->status === 422 ? ['profile_picture' => [$e->getMessage()]] : null,
            ], $e->status);
        } catch (Throwable $e) {
            Log::error('Unexpected profile picture upload failure: ' . $e->getMessage());

            return response()->json(['status' => 'error', 'message' => 'Unable to update your profile picture. Please try again.'], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => $hadPicture ? 'Profile picture replaced successfully.' : 'Profile picture updated successfully.',
            'user' => $user->profilePayload(),
        ]);
    }

    /** DELETE /api/profile/picture */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $had = $user->hasProfilePicture();

        try {
            $user = $this->pictures->remove($user);
        } catch (Throwable $e) {
            Log::error('Profile picture removal failed: ' . $e->getMessage());

            return response()->json(['status' => 'error', 'message' => 'Unable to remove your profile picture. Please try again.'], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => $had ? 'Profile picture removed.' : 'No profile picture to remove.',
            'user' => $user->profilePayload(),
        ]);
    }

    /** GET /api/profile/picture — the session user's own image */
    public function show(Request $request): StreamedResponse|JsonResponse
    {
        return $this->stream($request->user());
    }

    /** GET /api/users/{user}/profile-picture — another faculty member's image (policy-gated) */
    public function showUser(Request $request, User $user): StreamedResponse|JsonResponse
    {
        if (!$request->user()->can('viewProfilePicture', $user)) {
            return response()->json(['status' => 'error', 'message' => 'You are not allowed to view this profile picture.'], 403);
        }

        return $this->stream($user);
    }

    protected function stream(User $user): StreamedResponse|JsonResponse
    {
        $resolved = $this->pictures->resolve($user);
        if ($resolved === null) {
            return response()->json(['status' => 'error', 'message' => 'No profile picture found.'], 404);
        }

        $maxAge = max(0, (int) config('profile_picture.cache_max_age_seconds', 86400));
        $headers = [
            'Content-Type' => $resolved['mime'],
            'Cache-Control' => "private, max-age={$maxAge}",
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="profile-picture.' . pathinfo($resolved['path'], PATHINFO_EXTENSION) . '"',
        ];
        if ($resolved['size'] !== null) {
            $headers['Content-Length'] = (string) $resolved['size'];
        }

        // The disk response() sets Content-Type/Length/Disposition from the file; our explicit headers win.
        return $this->pictures->disk()->response($resolved['path'], null, $headers);
    }
}
