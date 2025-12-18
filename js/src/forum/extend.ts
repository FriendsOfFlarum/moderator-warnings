import Extend from 'flarum/common/extenders';
import Warning from './model/Warning';
import User from 'flarum/common/models/User';
import WarningNotification from './components/WarningNotification';

export default [
  new Extend.Store() //
    .add('warnings', Warning),

  new Extend.Model(User) //
    .attribute('canViewWarnings')
    .attribute('canManageWarnings')
    .attribute('canDeleteWarnings')
    .attribute('visibleWarningCount'),

  new Extend.Notification() //
    .add('warning', WarningNotification),
];
