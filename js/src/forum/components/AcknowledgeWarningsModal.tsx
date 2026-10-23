import app from 'flarum/forum/app';
import Modal, { IInternalModalAttrs } from 'flarum/common/components/Modal';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import username from 'flarum/common/helpers/username';
import type Warning from '../model/Warning';
import type Mithril from 'mithril';

/**
 * Shows the member every warning still awaiting their acknowledgement. It can
 * only be closed by acknowledging: the member can't post until they do anyway.
 */
export default class AcknowledgeWarningsModal extends Modal<IInternalModalAttrs> {
  protected static readonly isDismissibleViaCloseButton: boolean = false;
  protected static readonly isDismissibleViaEscKey: boolean = false;
  protected static readonly isDismissibleViaBackdropClick: boolean = false;

  warnings: Warning[] | null = null;

  oninit(vnode: Mithril.Vnode<IInternalModalAttrs, this>) {
    super.oninit(vnode);

    app.store.find<Warning[]>('warnings', { filter: { userId: app.session.user!.id()!, awaitingAcknowledgement: '1' } }).then(
      (warnings) => {
        // Nothing left to show (acknowledged in another tab, or removed by a
        // moderator since the page loaded): never trap the member behind an
        // empty modal they can't close.
        if (!warnings.length) return this.hide();

        this.warnings = warnings;
        m.redraw();
      },
      // The request error is already shown as an alert. The server still
      // enforces the block, so let the member carry on reading.
      () => this.hide()
    );
  }

  className() {
    return 'AcknowledgeWarningsModal Modal--medium';
  }

  title() {
    return app.translator.trans('fof-moderator-warnings.forum.acknowledge_modal.title');
  }

  content() {
    if (!this.warnings) {
      return (
        <div className="Modal-body">
          <LoadingIndicator />
        </div>
      );
    }

    return (
      <div className="Modal-body">
        <p className="helpText">{app.translator.trans('fof-moderator-warnings.forum.acknowledge_modal.text', { count: this.warnings.length })}</p>
        <ul className="AcknowledgeWarningsModal-list">
          {this.warnings.map((warning) => (
            <li className="AcknowledgeWarningsModal-warning">
              <p className="AcknowledgeWarningsModal-meta">
                {app.translator.trans('fof-moderator-warnings.forum.acknowledge_modal.warned_by', {
                  username: username(warning.addedByUser()),
                  time: humanTime(warning.createdAt()),
                })}
              </p>
              <div className="AcknowledgeWarningsModal-comment">{m.trust(warning.publicComment())}</div>
            </li>
          ))}
        </ul>
        <Button className="Button Button--primary Button--block" loading={this.loading} onclick={this.acknowledge.bind(this)}>
          {app.translator.trans('fof-moderator-warnings.forum.acknowledge_modal.submit_button')}
        </Button>
      </div>
    );
  }

  acknowledge() {
    this.loading = true;

    // One at a time rather than in parallel: each acknowledgement recomputes
    // the member's flag, and there is no reason to race those.
    this.warnings!.reduce<Promise<unknown>>(
      (previous, warning) =>
        previous.then(() =>
          app.request({
            method: 'POST',
            url: `${app.forum.attribute('apiUrl')}/warnings/${warning.id()}/acknowledge`,
          })
        ),
      Promise.resolve()
    ).then(
      // Reload rather than patch the store: every discussion's canReply and the
      // forum's canStartDiscussion were serialized while the member was blocked.
      () => window.location.reload(),
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }
}
