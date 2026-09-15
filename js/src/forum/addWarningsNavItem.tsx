import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

export default function addWarningsNavItem() {
  extend(IndexSidebar.prototype, 'navItems', function (items: ItemList<Mithril.Children>) {
    if (!app.forum.attribute<boolean>('canViewAllWarnings')) return;

    items.add(
      'warnings',
      <LinkButton href={app.route('warnings')} icon="fas fa-exclamation-circle">
        {app.translator.trans('fof-moderator-warnings.forum.index.warnings_link')}
      </LinkButton>,
      10
    );
  });
}
