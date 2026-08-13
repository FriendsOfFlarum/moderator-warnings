import FormModal, { IFormModalAttrs } from 'flarum/common/components/FormModal';
import Stream from 'flarum/common/utils/Stream';
import type Warning from '../model/Warning';
import type User from 'flarum/common/models/User';
import type Post from 'flarum/common/models/Post';
import type { AlertIdentifier } from 'flarum/common/states/AlertManagerState';
import type Mithril from 'mithril';
export interface IWarningModalAttrs extends IFormModalAttrs {
    user: User;
    post?: Post;
    callback?: (warning: Warning) => void;
}
export default class WarningModal<CustomAttrs extends IWarningModalAttrs = IWarningModalAttrs> extends FormModal<CustomAttrs> {
    publicComment: Stream<string>;
    privateComment: Stream<string>;
    strikes: Stream<number>;
    successAlert?: AlertIdentifier;
    oninit(vnode: Mithril.Vnode<CustomAttrs, this>): void;
    className(): string;
    title(): any[];
    content(): JSX.Element;
    onsubmit(e: SubmitEvent): void;
}
