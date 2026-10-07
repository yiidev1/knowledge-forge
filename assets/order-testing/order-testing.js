/* Order Testing — behaviour of its own.

   Deliberately empty for now. The store page's script, loaded by this bundle's dependency, already
   drives uploads, modals, polling, review, replacement and generated audio on this page: the template
   renders the same `data-a2t-*` attributes it binds to, carrying Order Testing's own URLs.

   This file exists so that when Order Testing's behaviour diverges, there is an obvious place for it
   that is not a branch inside the shared script. A conditional there — `if (page === 'order-testing')`
   — would couple the two surfaces at precisely the point they are meant to separate.

   Anything added here must stay scoped to its own attributes or classes, so a page without them starts
   no timer and no fetch loop, which is the rule the shared script already follows. */
