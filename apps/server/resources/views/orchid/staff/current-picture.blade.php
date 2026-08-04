{{--
    The staff member's current profile picture, rendered as an image (M18.20;
    VOL-013).

    The Cropper below this is an uploader, not a record: before this view the
    screen offered a file input with no way to see what is stored now, so an
    operator could not tell whether an upload took, or what the desk and rosters
    are showing for this person. As with branding assets, it is the picture that
    answers the question, and "no picture" is visibly a state rather than a
    broken image.
--}}
<div class="bg-white rounded shadow-sm p-3 d-flex align-items-center gap-3">
    @php($pictureUrl = $staff->profilePictureUrl())

    <div
        class="d-flex align-items-center justify-content-center rounded"
        style="width: 8rem; height: 8rem; background: #f6f1e8; overflow: hidden; flex: 0 0 auto;"
    >
        @if ($pictureUrl)
            <img
                src="{{ $pictureUrl }}"
                alt="{{ __('Current profile picture for :name', ['name' => $staff->displayName()]) }}"
                style="width: 100%; height: 100%; object-fit: cover;"
            >
        @else
            <span class="small text-muted text-center px-2">{{ __('No picture on record') }}</span>
        @endif
    </div>

    <div>
        <p class="small text-muted mb-1">{{ __('Current profile picture') }}</p>
        @if ($pictureUrl)
            <p class="small mb-0">
                {{ __('Uploaded :when', ['when' => $staff->profile_picture_uploaded_at?->toDayDateTimeString() ?? __('at an unrecorded time')]) }}
            </p>
        @else
            <p class="small mb-0">{{ __('This staff member has no profile picture. The uploader below sets one.') }}</p>
        @endif
    </div>
</div>
