import template from './sw-order-detail-general.html.twig';
import "./sw-order-detail-general.scss"

const { mapState } = Shopware.Component.getComponentHelper();

Shopware.Component.override('sw-order-detail-general', {
    template,
    createdComponent: function() {
        this.$super('createdComponent');

        console.log("Printess Component Loaded");
    },
    inject: [
        "printessAdminOrderService"
    ],
    computed: {
        ...mapState('swOrderDetail', [
            'order'
        ]),
        personalizedItems() {
            const ret = [];
            const that = this;

            that.order.lineItems.forEach((item => {
                if(item && item.payload && item.payload.hasOwnProperty("_printessSaveToken") && item.payload["_printessSaveToken"]) {
                    ret.push(item);
                }
            }));

            that.printessAdminOrderService.getOrderPrintStatus(that.order.id).then((status) => {
                if(status && status.data) {
                    for(const lineItemId in status.data) {
                        if(status.data.hasOwnProperty(lineItemId)) {
                            const lineItemNode = document.getElementById("printessResult" + lineItemId);

                            if(lineItemNode) {
                                const details = status.data[lineItemId];
                                const isFinished = details.isFinalStatus === true && details.isSuccess;
                                const notFound = !details.isFinalStatus && !details.isSuccess && !details.enqueuedOn;
                                const isError = details.errorDetails !== null;
                                const statusNode = lineItemNode.querySelector(".print-status > .value");

                                if(statusNode) {
                                    if(isError) {
                                        statusNode.innerText = "Error: " + details.errorDetails;
                                    } else if(notFound) {
                                        statusNode.innerText = "Printjob not found";
                                    } else if(isFinished) {
                                        const pdfList = document.createElement("ul");

                                        for(const docName in details.result.r) {
                                            if(details.result.r.hasOwnProperty(docName)) {
                                                const liNode = document.createElement("li");
                                                liNode.classList.add("print-document");
                                                const docNameNode = document.createElement("span");
                                                docNameNode.classList.add("document-name");
                                                docNameNode.innerText = docName + ": ";

                                                liNode.appendChild(docNameNode);

                                                const linkNode = document.createElement("a");
                                                linkNode.setAttribute("href", details.result.r[docName]);
                                                linkNode.setAttribute("target", "_blank");
                                                liNode.appendChild(linkNode);

                                                const linkLabelNode = document.createElement("span");
                                                linkLabelNode.innerText = details.result.r[docName];
                                                linkNode.appendChild(linkLabelNode);

                                                pdfList.appendChild(liNode);
                                            }
                                        }

                                        if(details.result.p) {
                                            for(let i = 0; i < details.result.p.length; ++i) {
                                                const liNode = document.createElement("li");
                                                liNode.classList.add("print-document");
                                                const docNameNode = document.createElement("span");
                                                docNameNode.classList.add("document-name");
                                                docNameNode.innerText = details.result.p[i]["d"] + ": ";

                                                liNode.appendChild(docNameNode);

                                                const linkNode = document.createElement("a");
                                                linkNode.setAttribute("href", details.result.p[i]["u"]);
                                                linkNode.setAttribute("target", "_blank");
                                                liNode.appendChild(linkNode);

                                                const linkLabelNode = document.createElement("span");
                                                linkLabelNode.innerText = details.result.p[i]["u"];
                                                linkNode.appendChild(linkLabelNode);

                                                pdfList.appendChild(liNode);
                                            }
                                        }

                                        statusNode.parentNode.replaceChild(pdfList, statusNode);
                                    }
                                }
                            }
                        }
                    }
                }
            })

            return ret;
        }
    }
});