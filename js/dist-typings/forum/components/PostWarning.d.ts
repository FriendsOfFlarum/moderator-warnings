import Component, { ComponentAttrs } from 'flarum/common/Component';
import type Mithril from 'mithril';
import type Warning from '../model/Warning';
export interface PostWarningAttrs extends ComponentAttrs {
    warning: Warning;
}
export default class PostWarning<CustomAttrs extends PostWarningAttrs = PostWarningAttrs> extends Component<CustomAttrs> {
    warning: Warning;
    oninit(vnode: Mithril.Vnode<CustomAttrs, this>): void;
    view(): JSX.Element;
    oncreate(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void;
}
