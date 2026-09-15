import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import type { IPageAttrs } from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Avatar from 'flarum/common/components/Avatar';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Placeholder from 'flarum/common/components/Placeholder';
import Select from 'flarum/common/components/Select';
import UserSelectionModal from 'flarum/common/components/UserSelectionModal';
import listItems from 'flarum/common/helpers/listItems';
import username from 'flarum/common/helpers/username';
import extractText from 'flarum/common/utils/extractText';
import ItemList from 'flarum/common/utils/ItemList';
import WarningListItem from './WarningListItem';
import WarningListState from '../states/WarningListState';
import type { WarningListParams, WarningStatus } from '../states/WarningListState';
import type Warning from '../model/Warning';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';

const STATUSES: WarningStatus[] = ['active', 'removed'];
const DEFAULT_SORT = 'newest';

function isStatus(value: unknown): value is WarningStatus {
  return typeof value === 'string' && (STATUSES as string[]).includes(value);
}

/**
 * The page's filters as they appear in the URL.
 */
interface WarningsPageRouteParams {
  user?: string;
  status?: string;
  sort?: string;
}

export interface IWarningsPageAttrs extends IPageAttrs {}

/**
 * The `WarningsPage` lists every warning on the forum for moderators, with filters for the
 * warned user, the warning's status and the sort order.
 *
 * The filters live in the URL (`?user=`, `?status=`, `?sort=`), so a filtered view survives
 * a reload and can be shared. Changing a filter navigates, and the route resolver builds a
 * fresh page for the new parameters.
 */
export default class WarningsPage<CustomAttrs extends IWarningsPageAttrs = IWarningsPageAttrs> extends Page<CustomAttrs> {
  list: WarningListState | null = null;

  /**
   * The validated filters from the URL. Kept apart from the list's own params so the
   * selects show the right value while the user filter is still being resolved.
   */
  params: WarningListParams = {};

  /**
   * The user the list is filtered by, once resolved from the `user` route parameter.
   */
  user: User | null = null;

  loadingUser = false;

  oninit(vnode: Mithril.Vnode<CustomAttrs, this>) {
    super.oninit(vnode);

    // The server refuses direct loads without the permission; this covers navigating
    // here from within the app.
    if (!app.forum.attribute<boolean>('canViewAllWarnings')) {
      m.route.set(app.route('index'));
      return;
    }

    app.history.push('warnings', extractText(app.translator.trans('fof-moderator-warnings.forum.warnings_page.title')));

    this.bodyClass = 'App--warnings';

    const list = (this.list = new WarningListState());
    const status = m.route.param('status');
    const sort = m.route.param('sort');

    // Only those who can manage warnings get the status filter; anyone else following a
    // shared `?status=removed` link would otherwise see an empty list they can't clear.
    const params: WarningListParams = (this.params = {
      status: isStatus(status) && app.session.user?.canManageWarnings() ? status : undefined,
      sort: sort && sort in list.sortMap() ? sort : undefined,
    });

    const slug = m.route.param('user');

    if (!slug) {
      list.refreshParams(params, 1);
      return;
    }

    this.loadingUser = true;

    this.findUser(slug).then(
      (user) => {
        this.user = user;
        this.loadingUser = false;
        list.refreshParams({ ...params, userId: user.id() }, 1);
      },
      () => {
        // An unknown or deleted user: drop the filter rather than show an empty list
        // that looks like the user has no warnings.
        this.loadingUser = false;
        this.navigate({ user: undefined });
      }
    );
  }

  oncreate(vnode: Mithril.VnodeDOM<CustomAttrs, this>) {
    super.oncreate(vnode);

    // Redirected away in oninit.
    if (!this.list) return;

    app.setTitle(extractText(app.translator.trans('fof-moderator-warnings.forum.warnings_page.title')));
    app.setTitleCount(0);
  }

  view() {
    if (!this.list) return null;

    return (
      <PageStructure className="WarningsPage" sidebar={() => <IndexSidebar />}>
        {this.contentItems().toArray()}
      </PageStructure>
    );
  }

  contentItems(): ItemList<Mithril.Children> {
    const items = new ItemList<Mithril.Children>();

    items.add('title', <h2 className="WarningsPage-title">{app.translator.trans('fof-moderator-warnings.forum.warnings_page.title')}</h2>, 100);

    items.add(
      'toolbar',
      <div className="Warnings-toolbar WarningsPage-toolbar">
        <ul className="Warnings-toolbar-action">{listItems(this.actionItems().toArray())}</ul>
        <ul className="Warnings-toolbar-view">{listItems(this.viewItems().toArray())}</ul>
      </div>,
      90
    );

    items.add('warnings', this.warningsView(), 80);

    return items;
  }

  /**
   * The user filter.
   */
  actionItems(): ItemList<Mithril.Children> {
    const items = new ItemList<Mithril.Children>();

    if (this.user) {
      items.add(
        'user',
        <Button className="Button WarningsPage-user" onclick={this.selectUser.bind(this)}>
          <Avatar user={this.user} /> {username(this.user)}
        </Button>,
        100
      );

      items.add(
        'clearUser',
        <Button
          className="Button Button--icon"
          icon="fas fa-times"
          aria-label={extractText(app.translator.trans('fof-moderator-warnings.forum.warnings_page.clear_user_button'))}
          onclick={() => this.navigate({ user: undefined })}
        />,
        90
      );
    } else {
      items.add(
        'user',
        <Button className="Button" icon="fas fa-user" disabled={this.loadingUser} onclick={this.selectUser.bind(this)}>
          {app.translator.trans('fof-moderator-warnings.forum.warnings_page.filter_user_button')}
        </Button>,
        100
      );
    }

    return items;
  }

  /**
   * The status and sort selects.
   */
  viewItems(): ItemList<Mithril.Children> {
    const items = new ItemList<Mithril.Children>();
    const trans = (key: string) => extractText(app.translator.trans(`fof-moderator-warnings.forum.warnings_page.${key}`));

    // Removed warnings are only visible to those who can manage warnings, so the status
    // filter would have nothing to tell anyone else apart.
    if (app.session.user?.canManageWarnings()) {
      items.add(
        'status',
        <Select
          options={{
            all: trans('status_all'),
            active: trans('status_active'),
            removed: trans('status_removed'),
          }}
          value={this.params.status || 'all'}
          onchange={(value: string) => this.navigate({ status: value === 'all' ? undefined : value })}
        />,
        100
      );
    }

    items.add(
      'sort',
      <Select
        options={{
          newest: trans('sort_newest'),
          oldest: trans('sort_oldest'),
        }}
        value={this.params.sort || DEFAULT_SORT}
        onchange={(value: string) => this.navigate({ sort: value === DEFAULT_SORT ? undefined : value })}
      />,
      90
    );

    return items;
  }

  warningsView(): Mithril.Children {
    const list = this.list!;

    if (this.loadingUser || list.isInitialLoading()) {
      return <LoadingIndicator />;
    }

    const warnings = this.warnings();

    if (!warnings.length) {
      return <Placeholder text={app.translator.trans('fof-moderator-warnings.forum.warnings_page.empty_text')} />;
    }

    return (
      <div className="WarningList WarningsPage-list">
        <ul className="WarningList-Warnings">
          {warnings.map((warning) => (
            <li key={`warning${warning.id()}`} data-id={warning.id()}>
              <WarningListItem warning={warning} showWarnedUser={true} ondelete={this.removeWarning.bind(this)} onchange={() => m.redraw()} />
            </li>
          ))}
        </ul>
        {list.hasNext() ? (
          <div className="WarningList-loadMore">
            <Button className="Button" loading={list.isLoadingNext()} onclick={() => list.loadNext()}>
              {app.translator.trans('fof-moderator-warnings.forum.warnings_page.load_more_button')}
            </Button>
          </div>
        ) : null}
      </div>
    );
  }

  /**
   * Loaded warnings across all pages, skipping entries the store no longer holds.
   */
  warnings(): Warning[] {
    return (this.list?.getPages() ?? []).flatMap((page) => page.items as Warning[]).filter((warning) => warning && typeof warning.id === 'function');
  }

  /**
   * Resolve the `user` route parameter the same way the user profile does.
   */
  findUser(slug: string): Promise<User> {
    const lowercase = slug.toLowerCase();
    const known = app.store.all<User>('users').find((user) => user.slug() === slug || user.username().toLowerCase() === lowercase);

    if (known) {
      return Promise.resolve(known);
    }

    return app.store.find<User>('users', slug, { bySlug: true });
  }

  selectUser() {
    app.modal.show(UserSelectionModal, {
      title: extractText(app.translator.trans('fof-moderator-warnings.forum.warnings_page.user_modal_title')),
      maxItems: 1,
      selected: this.user ? [this.user] : [],
      onsubmit: (users: User[]) => {
        const user = users[0];

        if (user) {
          this.navigate({ user: user.slug() });
        }
      },
    });
  }

  /**
   * Drop a permanently deleted warning from the list without reloading it.
   */
  removeWarning(warning: Warning) {
    this.list?.remove(warning);
    m.redraw();
  }

  /**
   * Apply filter changes by navigating, so they are reflected in the URL.
   */
  navigate(changes: WarningsPageRouteParams) {
    const params: Record<string, string> = {};
    const merged: WarningsPageRouteParams = {
      user: m.route.param('user'),
      status: m.route.param('status'),
      sort: m.route.param('sort'),
      ...changes,
    };

    (Object.keys(merged) as (keyof WarningsPageRouteParams)[]).forEach((key) => {
      const value = merged[key];

      if (value) {
        params[key] = value;
      }
    });

    m.route.set(app.route('warnings', params));
  }
}
