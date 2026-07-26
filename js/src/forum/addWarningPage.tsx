import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import UserPage from 'flarum/forum/components/UserPage';
import LinkButton from 'flarum/common/components/LinkButton';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

export default function addWarningPage() {
  extend(UserPage.prototype, 'navItems', function (items: ItemList<Mithril.Children>) {
    const user = this.user;

    // canViewWarnings is relative to the profile user: true for moderators on
    // any profile, but also always true on the actor's own profile. So on
    // one's own profile, only show the link once there are visible warnings.
    const isSelf = user && app.session.user && user.id() === app.session.user.id();

    if (app.session.user && user && user.canViewWarnings() && (!isSelf || user.visibleWarningCount() > 0)) {
      items.add(
        'warnings',
        <LinkButton
          href={app.route('user.warnings', {
            username: user.slug(),
          })}
          icon="fas fa-exclamation-circle"
        >
          {app.translator.trans('fof-moderator-warnings.forum.user.warnings')}
          {user.visibleWarningCount() > 0 ? <span className="Button-badge">{user.visibleWarningCount()}</span> : ''}
        </LinkButton>,
        10
      );
    }
  });
}
