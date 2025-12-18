import Model from 'flarum/common/Model';
import User from 'flarum/common/models/User';
import Post from 'flarum/common/models/Post';
export default class Warning extends Model {
    publicComment(): string;
    privateComment(): string | null;
    strikes(): number;
    createdAt(): Date;
    hiddenAt(): Date | null;
    isHidden(): boolean;
    warnedUser(): false | User;
    hiddenByUser(): false | User | null;
    addedByUser(): false | User;
    post(): false | Post | null;
}
