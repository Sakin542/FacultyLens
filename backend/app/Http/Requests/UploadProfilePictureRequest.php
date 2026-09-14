<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadProfilePictureRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The picture always belongs to the session user; there is no target id to authorise.
        return $this->user() !== null;
    }

    /**
     * MIME is sniffed from the bytes (mimetypes), size in KB, dimensions via the image header.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $maxKb = max(1, (int) config('profile_picture.max_size_mb', 5)) * 1024;
        $min = (int) config('profile_picture.min_dimension', 100);
        $max = (int) config('profile_picture.max_dimension', 5000);
        $mimes = implode(',', (array) config('profile_picture.allowed_mime_types', ['image/jpeg', 'image/png', 'image/webp']));

        return [
            'profile_picture' => [
                'required',
                'file',
                "max:{$maxKb}",
                "mimetypes:{$mimes}",
                'extensions:' . implode(',', (array) config('profile_picture.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp'])),
                "dimensions:min_width={$min},min_height={$min},max_width={$max},max_height={$max}",
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $maxMb = (int) config('profile_picture.max_size_mb', 5);
        $min = (int) config('profile_picture.min_dimension', 100);
        $max = (int) config('profile_picture.max_dimension', 5000);

        return [
            'profile_picture.required' => 'Please select an image to upload.',
            'profile_picture.file' => 'The uploaded item must be a valid file.',
            'profile_picture.max' => "Profile picture must be {$maxMb} MB or smaller.",
            'profile_picture.mimetypes' => 'Please upload a JPG, PNG, or WEBP image.',
            'profile_picture.extensions' => 'Please upload a JPG, PNG, or WEBP image.',
            'profile_picture.dimensions' => "Profile picture must be between {$min} × {$min} and {$max} × {$max} pixels.",
        ];
    }

    public function attributes(): array
    {
        return ['profile_picture' => 'profile picture'];
    }
}
