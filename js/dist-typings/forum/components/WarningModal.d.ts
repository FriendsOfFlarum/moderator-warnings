export default class WarningModal extends FormModal<import("flarum/common/components/FormModal").IFormModalAttrs, undefined> {
    constructor();
    oninit(vnode: any): void;
    publicComment: any;
    privateComment: any;
    strikes: any;
    title(): any[];
    content(): JSX.Element;
    onsubmit(e: any): void;
    successAlert: number | undefined;
}
import FormModal from "flarum/common/components/FormModal";
