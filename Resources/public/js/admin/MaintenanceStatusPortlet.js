/* global pimcore, Ext */

pimcore.registerNS('pimcore.bundle.twochain_advanced_maintenance_mode.MaintenanceStatusPortlet');

pimcore.bundle.twochain_advanced_maintenance_mode.MaintenanceStatusPortlet = Class.create(pimcore.layout.portlets.abstract, {

    getType: function () {
        return 'pimcore.bundle.twochain_advanced_maintenance_mode.MaintenanceStatusPortlet';
    },

    getName: function () {
        return 'Advanced Maintenance Status';
    },

    getIcon: function () {
        return 'pimcore_nav_icon_warning';
    },

    getLayout: function (portletId) {
        var me = this;
        var layout = this.parent(portletId);
        layout.on('render', function () { me.reload(); });
        return layout;
    },

    reload: function () {
        var me = this;
        Ext.Ajax.request({
            url: '/admin/advanced-maintenance-mode/schedules',
            success: function (resp) {
                var data = Ext.decode(resp.responseText);
                var act = data.currentActivation;
                var html;
                if (act && act.activatedByScheduleWindowId) {
                    html = '<div style="color:red;font-weight:bold;padding:8px">MAINTENANCE ACTIVE</div>';
                    if (act.reason) { html += '<div style="padding:4px 8px">Reason: ' + Ext.htmlEncode(act.reason) + '</div>'; }
                } else {
                    html = '<div style="color:green;padding:8px">No active maintenance</div>';
                    var next = (data.windows || []).filter(function (w) { return !w.activeNow; })[0];
                    if (next) {
                        html += '<div style="padding:4px 8px">Next: ' + Ext.htmlEncode(next.id) + (next.from ? ' at ' + Ext.htmlEncode(next.from) : '') + '</div>';
                    }
                }
                if (me.layout && me.layout.body) {
                    me.layout.body.update(html);
                }
            },
            failure: function () {
                if (me.layout && me.layout.body) {
                    me.layout.body.update('<div style="padding:8px;color:gray">Could not load maintenance status.</div>');
                }
            }
        });
    }
});
