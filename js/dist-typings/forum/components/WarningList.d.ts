import Component, { ComponentAttrs } from 'flarum/common/Component';
import ItemList from 'flarum/common/utils/ItemList';
import type Warning from '../model/Warning';
import type User from 'flarum/common/models/User';
import type Mithril from 'mithril';
export interface IWarningListAttrs extends ComponentAttrs {
    params: {
        user: User;
        sort?: string;
    };
}
export default class WarningList<CustomAttrs extends IWarningListAttrs = IWarningListAttrs> extends Component<CustomAttrs> {
    loading: boolean;
    warnings: Warning[];
    user: User;
    oninit(vnode: Mithril.Vnode<CustomAttrs, this>): void;
    view(): JSX.Element;
    actionItems(): ItemList<Mithril.Children>;
    /**
     * The children of the warnings list, every one of them keyed.
     */
    warningItems(): Mithril.Children[];
    /**
     * Renderable warnings, skipping entries the store no longer holds.
     */
    visibleWarnings(): Warning[];
    strikeCount(): number;
    parseResults(results: Warning[]): Warning[];
    refresh(): Promise<void>;
    /**
     * Show a newly created warning without refetching the list.
     */
    addWarning(warning: Warning | null | undefined): void;
    /**
     * Drop a deleted warning from the list without reloading the page.
     */
    removeWarning(warning: Warning | null | undefined): void;
    /**
     * Keep the profile badge in step with the list.
     */
    syncWarningCount(): void;
    handleOnClickCreate(e: MouseEvent): void;
}
