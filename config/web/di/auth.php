<?php

declare(strict_types=1);

use App\Auth\Application\AdminIdentityStoreInterface;
use App\Auth\Infrastructure\SessionAdminIdentityStore;

// Web-only: the identity store depends on the PSR session, which exists only in an HTTP request.
// CurrentAdmin, RequireAdminMiddleware, LoginService, Redirect and FlashMessages are all autowired
// from their constructor types and need no explicit definition here.
return [
    AdminIdentityStoreInterface::class => SessionAdminIdentityStore::class,
];
