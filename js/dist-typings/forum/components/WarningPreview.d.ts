import Component, { ComponentAttrs } from 'flarum/common/Component';
import type Warning from '../model/Warning';
import type Mithril from 'mithril';
export interface IWarningPreviewAttrs extends ComponentAttrs {
    warning: Warning;
}
export default class WarningPreview<CustomAttrs extends IWarningPreviewAttrs = IWarningPreviewAttrs> extends Component<CustomAttrs> {
    warning: Warning;
    oninit(vnode: Mithril.Vnode<CustomAttrs, this>): void;
    view(): JSX.Element;
}
