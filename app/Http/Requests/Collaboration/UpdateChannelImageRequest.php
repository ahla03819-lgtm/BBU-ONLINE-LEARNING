<?php

namespace App\Http\Requests\Collaboration;

use App\Enums\ChannelType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChannelImageRequest extends FormRequest
{
    /**
     * Channel images are a Custom-channel-only feature. The Custom check is
     * repeated here rather than relying solely on ChannelPolicy::update,
     * because Gate::before short-circuits every policy for a Super Admin.
     */
    public function authorize(): bool
    {
        $channel = $this->route('channel');

        return $channel !== null
            && $channel->type === ChannelType::Custom
            && $this->user()->can('update', $channel);
    }

    public function rules(): array
    {
        return ['image' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120']];
    }
}