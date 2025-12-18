import Model from 'flarum/common/Model';
import computed from 'flarum/common/utils/computed';
import User from 'flarum/common/models/User';
import Post from 'flarum/common/models/Post';

export default class Warning extends Model {
  publicComment() {
    return Model.attribute<string>('publicComment').call(this);
  }

  privateComment() {
    return Model.attribute<string | null>('privateComment').call(this);
  }

  strikes() {
    return Model.attribute<number>('strikes').call(this);
  }

  createdAt() {
    return Model.attribute<Date, string>('createdAt', Model.transformDate).call(this);
  }

  hiddenAt() {
    return Model.attribute<Date | null, string>('hiddenAt', Model.transformDate).call(this);
  }

  isHidden() {
    return computed<boolean>('hiddenAt', (hiddenAt) => !!hiddenAt).call(this);
  }

  warnedUser() {
    return Model.hasOne<User>('warnedUser').call(this);
  }

  hiddenByUser() {
    return Model.hasOne<User | null>('hiddenByUser').call(this);
  }

  addedByUser() {
    return Model.hasOne<User>('addedByUser').call(this);
  }

  post() {
    return Model.hasOne<Post | null>('post').call(this);
  }
}
