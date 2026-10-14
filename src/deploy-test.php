<?php

declare(strict_types=1);

// Knowledge Forge deployment synchronization test.
//
// This file exists only to prove that `deploy.sh` notices a new file under `src/` and puts it at the
// matching path on the server. It declares nothing, loads nothing and runs nothing: no namespace, no
// class, no autoloadable symbol, no configuration and no route refers to it, so nothing in the
// application can reach it and it cannot be requested over HTTP.
//
// Delete it locally once the first deployment has been verified, then run `./deploy.sh` again: it will
// appear under "Files to delete", and `./deploy.sh deploy` will remove it from the server — which
// tests the other half of the synchronisation.
