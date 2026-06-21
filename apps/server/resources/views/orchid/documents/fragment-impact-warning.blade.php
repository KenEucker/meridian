@if ($fragment->exists && $publishedReferenceCount > 0)
    <div class="alert alert-warning mb-0" role="alert">
        <h2 class="h5 alert-heading">Published document impact</h2>
        <p class="mb-0">
            {{ trans_choice('Editing this fragment will bump versions for :count published document.|Editing this fragment will bump versions for :count published documents.', $publishedReferenceCount, ['count' => $publishedReferenceCount]) }}
        </p>
    </div>
@endif
