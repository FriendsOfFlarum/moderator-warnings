import Component, { ComponentAttrs } from 'flarum/common/Component';
import type Post from 'flarum/common/models/Post';
import type Mithril from 'mithril';
export interface IWarningPostAttrs extends ComponentAttrs {
    post: Post;
}
export default class WarningPost<CustomAttrs extends IWarningPostAttrs = IWarningPostAttrs> extends Component<CustomAttrs> {
    view(): JSX.Element;
    oncreate(vnode: Mithril.VnodeDOM<CustomAttrs, this>): void;
}
