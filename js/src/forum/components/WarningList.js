import Component from 'flarum/common/Component';
import app from 'flarum/forum/app';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import WarningListItem from './WarningListItem';
import Button from 'flarum/common/components/Button';
import WarningModal from './WarningModal';
import listItems from 'flarum/common/helpers/listItems';
import ItemList from 'flarum/common/utils/ItemList';

export default class WarningList extends Component {
  oninit(vnode) {
    super.oninit(vnode);
    this.loading = true;
    this.warnings = [];
    this.user = this.attrs.params.user;
    this.refresh();
  }

  view() {
    let loading;

    if (this.loading) {
      loading = LoadingIndicator.component({ size: 'large' });
    }

    return (
      <div className="WarningList">
        <h1 className="WarningList-warnings">
          {this.strikeCount()
            ? app.translator.trans('fof-moderator-warnings.forum.warning_list.warnings', { strikes: this.strikeCount() || 0 })
            : app.translator.trans('fof-moderator-warnings.forum.warning_list.warnings_no_strikes')}
        </h1>
        <div class="Warnings-toolbar">
          <ul className="Warnings-toolbar-action">{listItems(this.actionItems().toArray())}</ul>
        </div>
        <ul className="WarningList-Warnings">{this.warningItems()}</ul>
        <div className="WarningList-loadMore">{loading}</div>
      </div>
    );
  }

  actionItems() {
    const items = new ItemList();

    if (app.session.user.canManageWarnings()) {
      items.add(
        'create_warning',
        <Button className="Button Button--primary" onclick={this.handleOnClickCreate.bind(this)}>
          {app.translator.trans('fof-moderator-warnings.forum.warning_list.add_button')}
        </Button>
      );
    }

    return items;
  }

  /**
   * The children of the warnings list, every one of them keyed.
   *
   * @return {import('mithril').Children[]}
   */
  warningItems() {
    const items = this.visibleWarnings().map((warning) => (
      <li key={`warning${warning.id()}`} data-id={warning.id()}>
        {WarningListItem.component({
          warning,
          // Not `onremove`: that is a mithril lifecycle hook, which would be invoked
          // with the vnode when the row itself is torn down.
          ondelete: this.removeWarning.bind(this),
          onchange: this.syncWarningCount.bind(this),
        })}
      </li>
    ));

    if (!this.loading && !items.length) {
      items.push(
        <li key="empty">
          <label>{app.translator.trans('fof-moderator-warnings.forum.warning_list.no_warnings')}</label>
        </li>
      );
    }

    return items;
  }

  /**
   * Renderable warnings, skipping entries the store no longer holds.
   *
   * @return {Warning[]}
   */
  visibleWarnings() {
    return this.warnings.filter((warning) => warning && typeof warning.id === 'function');
  }

  strikeCount() {
    return this.visibleWarnings()
      .filter((warning) => !warning.isHidden())
      .map((warning) => warning.strikes())
      .reduce((a, b) => a + b, 0);
  }

  parseResults(results) {
    [].push.apply(this.warnings, [...results].filter(Boolean));
    this.loading = false;
    m.redraw();

    return results;
  }

  refresh() {
    this.loading = true;

    return app.store.find('warnings', { filter: { userId: this.user.id() } }).then(
      (results) => {
        this.warnings = [];
        this.parseResults(results);
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  /**
   * Show a newly created warning without refetching the list.
   *
   * @param {Warning} warning
   */
  addWarning(warning) {
    if (!warning || typeof warning.id !== 'function') return;

    // The list is sorted newest-first, so a new warning belongs at the top.
    this.warnings.unshift(warning);

    this.syncWarningCount();

    m.redraw();
  }

  /**
   * Drop a deleted warning from the list without reloading the page.
   *
   * @param {Warning} warning
   */
  removeWarning(warning) {
    if (!warning || typeof warning.id !== 'function') return;

    const id = warning.id();

    this.warnings = this.warnings.filter((w) => w && typeof w.id === 'function' && w.id() !== id);

    this.syncWarningCount();

    m.redraw();
  }

  /**
   * Keep the profile badge in step with the list.
   */
  syncWarningCount() {
    this.user.pushAttributes({
      visibleWarningCount: this.visibleWarnings().filter((warning) => !warning.isHidden()).length,
    });
  }

  handleOnClickCreate(e) {
    e.preventDefault();
    app.modal.show(WarningModal, {
      callback: this.addWarning.bind(this),
      ...this.attrs.params,
    });
  }
}
