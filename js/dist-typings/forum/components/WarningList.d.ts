export default class WarningList extends Component<any, undefined> {
    constructor();
    oninit(vnode: any): void;
    loading: boolean | undefined;
    warnings: any;
    user: any;
    view(): JSX.Element;
    actionItems(): ItemList<any>;
    /**
     * The children of the warnings list, every one of them keyed.
     *
     * @return {import('mithril').Children[]}
     */
    warningItems(): import('mithril').Children[];
    /**
     * Renderable warnings, skipping entries the store no longer holds.
     *
     * @return {Warning[]}
     */
    visibleWarnings(): Warning[];
    strikeCount(): any;
    parseResults(results: any): any;
    refresh(): Promise<void>;
    /**
     * Show a newly created warning without refetching the list.
     *
     * @param {Warning} warning
     */
    addWarning(warning: Warning): void;
    /**
     * Drop a deleted warning from the list without reloading the page.
     *
     * @param {Warning} warning
     */
    removeWarning(warning: Warning): void;
    /**
     * Keep the profile badge in step with the list.
     */
    syncWarningCount(): void;
    handleOnClickCreate(e: any): void;
}
import Component from "flarum/common/Component";
import ItemList from "flarum/common/utils/ItemList";
