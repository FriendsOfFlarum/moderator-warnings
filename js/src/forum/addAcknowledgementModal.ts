import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Page from 'flarum/common/components/Page';

/**
 * A member with a warning to acknowledge is shown it once per page load, on
 * whichever page they land. The server already refuses their posts until they
 * do; this is how they find out why. The modal is a lazy chunk, so members
 * with nothing to acknowledge never download it.
 */
export default function () {
  let checked = false;

  extend(Page.prototype, 'oninit', function () {
    if (checked) return;

    checked = true;

    if (app.session.user?.hasUnacknowledgedWarnings()) {
      // Deferred: opening a modal while the first page is still initialising
      // triggers a redraw error.
      setTimeout(() => app.modal.show(() => import('./components/AcknowledgeWarningsModal')), 0);
    }
  });
}
