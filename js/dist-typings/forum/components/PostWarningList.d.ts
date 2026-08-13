import Component, { ComponentAttrs } from 'flarum/common/Component';
import type Post from 'flarum/common/models/Post';
import type Mithril from 'mithril';
export interface IPostWarningListAttrs extends ComponentAttrs {
    post: Post;
}
export default class PostWarningList<CustomAttrs extends IPostWarningListAttrs = IPostWarningListAttrs> extends Component<CustomAttrs> {
    post: Post;
    oninit(vnode: Mithril.Vnode<CustomAttrs, this>): void;
    view(): JSX.Element;
}
