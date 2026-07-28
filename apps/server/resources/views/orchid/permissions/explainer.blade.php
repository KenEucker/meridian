{{--
    Why there are two role lists in this console.

    The framework's own Roles screen and this one look like they should be the
    same thing and are not, which is a genuinely confusing thing to walk into.
    Stating it here costs a paragraph and saves an operator from granting
    console access when they meant to grant an operational capability.
--}}
<div class="bg-white rounded shadow-sm p-4 mb-3">
    <p class="mb-3">
        This is the permission model Meridian runs on: the roles a person can hold,
        the scope each is held at, and the capabilities each carries. It is defined
        by this build in <code>App\Domain\Permissions\PermissionCatalog</code> and
        seeded from there, so every node running this build has the same catalog.
    </p>

    <div class="alert alert-info mb-0" role="note">
        <strong class="d-block mb-1">{{ __('This is not the same as Roles under Access Controls.') }}</strong>
        {{ __('That screen is the administrative framework\'s own role list, and it controls who may open this console. It has no effect on what anyone can do in Meridian. Console access in this deployment is granted per user rather than through those roles, which is why that list is normally empty.') }}
    </div>
</div>
