import PaginatedListState from 'flarum/common/states/PaginatedListState';
import type { PaginatedListParams, PaginatedListRequestParams, SortMap } from 'flarum/common/states/PaginatedListState';
import type Warning from '../model/Warning';

export type WarningStatus = 'active' | 'removed';

export interface WarningListParams extends PaginatedListParams {
  /**
   * Only list warnings given to this user.
   */
  userId?: string;

  /**
   * Only list active or only removed warnings. Both are listed when omitted.
   */
  status?: WarningStatus;

  /**
   * A key of `sortMap()`.
   */
  sort?: string;
}

/**
 * The paginated, filterable list of warnings shown on the forum-wide warnings page.
 */
export default class WarningListState extends PaginatedListState<Warning, WarningListParams> {
  constructor(params: WarningListParams = {}, page: number = 1) {
    super(params, page, null);
  }

  get type(): string {
    return 'warnings';
  }

  protected requestParams(): PaginatedListRequestParams {
    const filter: Record<string, string> = {};

    if (this.params.userId) {
      filter.userId = this.params.userId;
    }

    if (this.params.status) {
      filter.hidden = this.params.status === 'removed' ? '1' : '0';
    }

    const params: PaginatedListRequestParams = {
      // Unlike a profile's warning list, every row here belongs to a different user, so
      // the warned user has to come along with the warning.
      include: ['warnedUser', 'addedByUser', 'post', 'post.discussion'],
      filter,
    };

    const sort = this.currentSort();

    if (sort) {
      params.sort = sort;
    }

    return params;
  }

  sortMap(): SortMap {
    return {
      newest: '-createdAt',
      oldest: 'createdAt',
    };
  }
}
