import Notification from 'flarum/forum/components/Notification';
/**
 * The `WarningNotification` component displays a notification which
 * indicates that a user has been warned by a moderator.
 */
export default class WarningNotification extends Notification {
    icon(): string;
    href(): string;
    content(): any[];
    excerpt(): null;
}
