import Component, { ComponentAttrs } from 'flarum/common/Component';
import SubtreeRetainer from 'flarum/common/utils/SubtreeRetainer';
import type Warning from '../model/Warning';
import type Mithril from 'mithril';
export interface IWarningListItemAttrs extends ComponentAttrs {
    warning: Warning;
    ondelete?: (warning: Warning) => void;
    onchange?: (warning: Warning) => void;
}
export default class WarningListItem<CustomAttrs extends IWarningListItemAttrs = IWarningListItemAttrs> extends Component<CustomAttrs> {
    subtree: SubtreeRetainer;
    oninit(vnode: Mithril.Vnode<CustomAttrs, this>): void;
    onbeforeupdate(vnode: Mithril.VnodeDOM<CustomAttrs, this>): boolean;
    view(): JSX.Element;
    elementAttrs(): ComponentAttrs;
}
