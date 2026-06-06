/* global pimcore, Ext */

pimcore.registerNS('pimcore.bundle.twochain_advanced_maintenance_mode.startup');

pimcore.bundle.twochain_advanced_maintenance_mode.startup = Class.create({
    initialize: function () {
        Ext.util.CSS.createStyleSheet(
            '.pimcore_nav_icon_advanced_maintenance {' +
            'background-image: url(/bundles/pimcoreadmin/img/flat-white-icons/automatic.svg) !important; }',
            'amm-nav-icon'
        );
        document.addEventListener(pimcore.events.preMenuBuild, this.preMenuBuild.bind(this));
        document.addEventListener(pimcore.events.pimcoreReady, this.pimcoreReady.bind(this));
    },

    pimcoreReady: function () {
        if (pimcore.layout && pimcore.layout.portlets) {
            pimcore.layout.portlets.advancedMaintenanceModeStatus = pimcore.bundle.twochain_advanced_maintenance_mode.MaintenanceStatusPortlet;
        }
    },

    preMenuBuild: function (e) {
        var menu = e.detail.menu;
        var user = pimcore.globalmanager.get('user');
        if (!user.isAllowed('advanced_maintenance_manage')) {
            return;
        }
        if (!menu.extras || !Array.isArray(menu.extras.items)) {
            return;
        }
        menu.extras.items.push({
            text: 'Advanced Maintenance',
            iconCls: 'pimcore_nav_icon_advanced_maintenance',
            itemId: 'pimcore_menu_extras_advanced_maintenance',
            priority: 10,
            handler: this.openPanel.bind(this)
        });
    },

    openPanel: function () {
        var existing = Ext.getCmp('advanced_maintenance_panel');
        if (existing) {
            pimcore.helpers.openMainTab(existing);
            return;
        }
        var panel = new pimcore.bundle.twochain_advanced_maintenance_mode.MaintenanceWindowsPanel();
        pimcore.globalmanager.add('advanced_maintenance_panel', panel);
    }
});

function formatDuration(minutes) {
    if (minutes === null || minutes === undefined) { return '—'; }
    var m = parseInt(minutes, 10);
    if (isNaN(m) || m < 0) { return '—'; }
    if (m < 60) { return m + 'm'; }
    var h = Math.floor(m / 60);
    var rem = m % 60;
    return h + 'h ' + (rem < 10 ? '0' : '') + rem + 'm';
}

function formatDateTime(isoStr) {
    if (!isoStr) { return '—'; }
    var d = new Date(isoStr);
    if (isNaN(d.getTime())) { return '—'; }
    var options = { year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' };
    try {
        var tz = pimcore.globalmanager.get('user').timezone;
        if (tz) { options.timeZone = tz; }
    } catch (e) {}
    return d.toLocaleString(undefined, options);
}

function humanizeCron(expr) {
    if (!expr || !expr.trim()) { return expr; }
    var parts = expr.trim().split(/\s+/);
    if (parts.length !== 5) { return expr; }
    var min = parts[0], hrs = parts[1], dom = parts[2], mon = parts[3], dow = parts[4];
    var DAYS = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    var MONTHS = ['January','February','March','April','May','June',
                  'July','August','September','October','November','December'];
    function pad(n) { return (parseInt(n, 10) < 10 ? '0' : '') + parseInt(n, 10); }
    function timeStr() { return pad(hrs) + ':' + pad(min); }
    var hasFixedTime = /^\d+$/.test(hrs) && /^\d+$/.test(min);

    if (min === '*' && hrs === '*' && dom === '*' && mon === '*' && dow === '*') { return 'Every minute'; }
    if (/^\*\/\d+$/.test(min) && hrs === '*' && dom === '*' && mon === '*' && dow === '*') {
        return 'Every ' + min.slice(2) + ' minutes';
    }
    if (/^\d+$/.test(min) && hrs === '*' && dom === '*' && mon === '*' && dow === '*') {
        return parseInt(min, 10) === 0 ? 'Every hour' : 'Every hour at minute ' + min;
    }
    if (/^\*\/\d+$/.test(hrs) && dom === '*' && mon === '*' && dow === '*') {
        var hInt = parseInt(hrs.slice(2), 10);
        return (hInt === 1 ? 'Every hour' : 'Every ' + hInt + ' hours') +
               (hasFixedTime ? ' at :' + pad(min) : '');
    }
    if (dom === '*' && mon === '*' && dow === '*' && hasFixedTime) { return 'Every day at ' + timeStr(); }
    if (dom === '*' && mon === '*' && dow !== '*' && hasFixedTime) {
        if (/^\d+-\d+$/.test(dow)) {
            var lo = parseInt(dow.split('-')[0], 10) % 7, hi = parseInt(dow.split('-')[1], 10) % 7;
            return (lo === 1 && hi === 5 ? 'Mon–Fri' : DAYS[lo] + '–' + DAYS[hi]) + ' at ' + timeStr();
        }
        var dayNames = dow.split(',').map(function (d) { return DAYS[parseInt(d, 10) % 7]; });
        return (dayNames.length === 1 ? 'Every ' + dayNames[0] : dayNames.join(', ')) + ' at ' + timeStr();
    }
    if (dom !== '*' && mon === '*' && dow === '*' && hasFixedTime && /^\d+$/.test(dom)) {
        var d = parseInt(dom, 10);
        return 'Monthly on the ' + d + ([,'st','nd','rd'][d] || 'th') + ' at ' + timeStr();
    }
    if (dom !== '*' && mon !== '*' && dow === '*' && hasFixedTime && /^\d+$/.test(dom) && /^\d+$/.test(mon)) {
        return 'Yearly on ' + MONTHS[parseInt(mon, 10) - 1] + ' ' + dom + ' at ' + timeStr();
    }
    return expr;
}

pimcore.registerNS('pimcore.bundle.twochain_advanced_maintenance_mode.MaintenanceWindowsPanel');

pimcore.bundle.twochain_advanced_maintenance_mode.MaintenanceWindowsPanel = Class.create({

    BASE_URL: '/admin/advanced-maintenance-mode',

    initialize: function () {
        Ext.util.CSS.createStyleSheet(
            '.amm-ex-chip{display:inline-flex;align-items:center;gap:4px;border-radius:3px;' +
            'padding:2px 7px;margin:2px 4px 2px 0;font-family:monospace;font-size:11px;border:1px solid;color:#fff}' +
            '.amm-ex-http{background:#388e3c;border-color:#2e7d34}' +
            '.amm-ex-ip{background:#e6900a;border-color:#c97d08}' +
            '.amm-ex-command{background:#c0392b;border-color:#a93226}' +
            '.amm-ex-builtin{background:#7b1fa2;border-color:#6a1b8e}' +
            '.amm-ex-unknown{background:#616161;border-color:#525252}',
            'amm-exemptions'
        );
        this.panel = this.buildPanel();
        var tabPanel = Ext.getCmp('pimcore_panel_tabs');
        tabPanel.add(this.panel);
        tabPanel.setActiveItem(this.panel);
        this.loadAll();
    },

    buildPanel: function () {
        var me = this;
        return new Ext.panel.Panel({
            id: 'advanced_maintenance_panel',
            title: 'Advanced Maintenance Mode',
            iconCls: 'pimcore_nav_icon_warning',
            border: false,
            layout: 'border',
            closable: true,
            items: [
                me.buildStatusStrip(),
                me.buildCenterContainer(),
                me.buildHistoryGrid()
            ],
            tbar: me.buildToolbar(),
            listeners: {
                destroy: function () {
                    pimcore.globalmanager.remove('advanced_maintenance_panel');
                }
            }
        });
    },

    buildToolbar: function () {
        var me = this;
        return [{
            text: 'Schedule Window',
            iconCls: 'pimcore_icon_add',
            handler: me.openCreateModal.bind(me)
        }, '-', {
            text: 'Refresh',
            iconCls: 'pimcore_icon_reload',
            handler: me.loadAll.bind(me)
        }];
    },

    buildStatusStrip: function () {
        this.statusStrip = new Ext.panel.Panel({
            region: 'north',
            height: 46,
            border: false,
            bodyStyle: 'background:transparent;padding:6px 8px',
            html: '<em style="color:#888">Loading…</em>'
        });
        return this.statusStrip;
    },

    buildExemptionsPanel: function () {
        this.exemptionsPanel = new Ext.panel.Panel({
            region: 'north',
            title: 'Exemptions',
            collapsible: true,
            collapsed: true,
            height: 220,
            bodyPadding: '6px 8px',
            bodyStyle: 'overflow-y:auto',
            html: '<em style="color:#888">Loading exemptions…</em>'
        });
        return this.exemptionsPanel;
    },

    buildCenterContainer: function () {
        var me = this;
        return new Ext.panel.Panel({
            region: 'center',
            layout: 'border',
            border: false,
            items: [
                me.buildExemptionsPanel(),
                me.buildWindowsGrid()
            ]
        });
    },

    buildWindowsGrid: function () {
        var me = this;

        this.windowsStore = Ext.create('Ext.data.Store', {
            fields: ['id', 'type', 'timezone', 'reason', 'from', 'to', 'cronExpression',
                     'durationMinutes', 'announceBeforeMinutes', 'createdByUsername',
                     'activeNow', 'queued', 'overlappingWith', 'nextFires',
                     { name: 'scope', type: 'auto' }],
            pageSize: 25,
            proxy: {
                type: 'memory',
                enablePaging: true,
                reader: { type: 'json' }
            }
        });

        return new Ext.grid.GridPanel({
            region: 'center',
            title: 'Scheduled Windows',
            store: this.windowsStore,
            columns: [
                { text: 'ID', dataIndex: 'id', width: 220, flex: 0 },
                { text: 'Type', dataIndex: 'type', width: 44, align: 'center',
                  renderer: function (v) {
                      var isRecurring = v === 'recurring';
                      var src = isRecurring
                          ? '/bundles/pimcoreadmin/img/flat-color-icons/data_backup.svg'
                          : '/bundles/pimcoreadmin/img/flat-color-icons/database.svg';
                      var tip = isRecurring ? 'Recurring' : 'One-time';
                      return '<img src="' + src + '" data-qtip="' + tip + '"' +
                             ' style="width:18px;height:18px;vertical-align:middle"/>';
                  }
                },
                { text: 'From / Cron', dataIndex: 'from', flex: 1,
                  renderer: function (v, m, r) {
                      var cron = r.get('cronExpression');
                      return Ext.htmlEncode(cron ? humanizeCron(cron) : formatDateTime(v));
                  }
                },
                { text: 'To / Duration', dataIndex: 'to', flex: 1,
                  renderer: function (v, m, r) {
                      var dur = r.get('durationMinutes');
                      return dur ? Ext.htmlEncode(formatDuration(dur)) : Ext.htmlEncode(formatDateTime(v));
                  }
                },
                { text: 'Reason', dataIndex: 'reason', flex: 1,
                  renderer: function (v) { return Ext.htmlEncode(v || ''); }
                },
                { text: 'Status', dataIndex: 'activeNow', width: 80,
                  renderer: function (v, m, r) {
                      if (r.get('activeNow')) { return '<b style="color:green">running</b>'; }
                      if (r.get('queued')) { return '<span style="color:#e8a07e">queued</span>'; }
                      return '<span style="color:#888">waiting</span>';
                  }
                },
                { text: 'Created by', dataIndex: 'createdByUsername', width: 100,
                  renderer: function (v) { return Ext.htmlEncode(v || ''); }
                },
                { text: t('Scope'), dataIndex: 'scope', width: 150,
                  renderer: function (scope) {
                      if (!scope || scope.global) {
                          return 'Global';
                      }
                      var parts = [];
                      if (scope.pathPrefixes && scope.pathPrefixes.length > 0) {
                          var paths = scope.pathPrefixes.join(', ');
                          parts.push(paths.length > 30 ? paths.substring(0, 29) + '…' : paths);
                      }
                      if (scope.siteIds && scope.siteIds.length > 0) {
                          parts.push('site ' + scope.siteIds.join(', '));
                      }
                      if (parts.length === 0) { return 'Global'; }
                      var summary = parts.join(' · ');
                      return '<span title="' + Ext.htmlEncode(
                          (scope.pathPrefixes || []).join(', ') +
                          (scope.siteIds && scope.siteIds.length ? ' · site ' + scope.siteIds.join(', ') : '')
                      ) + '">' + Ext.htmlEncode(summary) + '</span>';
                  }
                },
                { xtype: 'actioncolumn', width: 56, sortable: false,
                  items: [
                      { iconCls: 'pimcore_icon_stop', tooltip: 'End Now',
                        isDisabled: function (view, rowIndex, colIndex, item, record) {
                            return !record.get('activeNow');
                        },
                        handler: function (grid, rowIndex, colIndex, item, e, record) {
                            me.endNow(record.get('id'));
                        }
                      },
                      { iconCls: 'pimcore_icon_delete', tooltip: 'Delete',
                        handler: function (grid, rowIndex, colIndex, item, e, record) {
                            Ext.Msg.confirm('Delete', 'Delete this window?', function (btn) {
                                if (btn === 'yes') { me.deleteWindow(record.get('id')); }
                            });
                        }
                      }
                  ]
                }
            ],
            bbar: Ext.create('Ext.toolbar.Paging', {
                store: this.windowsStore,
                displayInfo: true,
                displayMsg: '{0} – {1} of {2}'
            })
        });
    },

    _sortAllWindows: function (windows) {
        return (windows || []).slice().sort(function (a, b) {
            var pa = a.activeNow ? 0 : a.queued ? 1 : 2;
            var pb = b.activeNow ? 0 : b.queued ? 1 : 2;
            if (pa !== pb) { return pa - pb; }
            var fa = a.from, fb = b.from;
            if (!fa && !fb) { return 0; }
            if (!fa) { return 1; }
            if (!fb) { return -1; }
            return fa < fb ? -1 : fa > fb ? 1 : 0;
        });
    },

    buildHistoryGrid: function () {
        var me = this;
        this.historyStore = Ext.create('Ext.data.Store', {
            fields: ['id', 'scheduleWindowId', 'startedAt', 'endedAt',
                     'durationMinutes', 'configuredDurationMinutes', 'type', 'reason',
                     'inProgress', 'overrun', 'endedReason'],
            pageSize: 25,
            proxy: {
                type: 'ajax',
                url: me.BASE_URL + '/schedules/history',
                pageParam:  'page',
                limitParam: 'pageSize',
                startParam: '',
                reader: {
                    type:          'json',
                    rootProperty:  'history',
                    totalProperty: 'total'
                }
            }
        });

        return new Ext.grid.GridPanel({
            region: 'south',
            title: 'History',
            height: 280,
            split: true,
            store: this.historyStore,
            columns: [
                { text: 'Window ID', dataIndex: 'scheduleWindowId', width: 220 },
                { text: 'Started', dataIndex: 'startedAt', flex: 1,
                  renderer: function (v) { return Ext.htmlEncode(formatDateTime(v)); }
                },
                { text: 'Ended', dataIndex: 'endedAt', flex: 1,
                  renderer: function (v) { return v ? Ext.htmlEncode(formatDateTime(v)) : '—'; }
                },
                { text: 'Duration', dataIndex: 'durationMinutes', width: 100,
                  renderer: function (v) { return Ext.htmlEncode(formatDuration(v)); }
                },
                { text: 'Overrun', dataIndex: 'overrun', width: 70,
                  renderer: function (v) {
                      if (v === null || v === undefined) { return '—'; }
                      return v ? '<b style="color:red">YES</b>' : 'no';
                  }
                },
                { text: 'Status', dataIndex: 'inProgress', width: 90,
                  renderer: function (v, m, r) {
                      if (r.get('inProgress')) { return '<b style="color:orange">running</b>'; }
                      var er = r.get('endedReason');
                      if (er === 'manual')    { return '<span style="color:#e8a07e">cancelled</span>'; }
                      if (er === 'schedule')  { return '<span style="color:#7ec8a4">completed</span>'; }
                      if (er === 'exception') { return '<b style="color:red">failed</b>'; }
                      return '<span style="color:#888">completed</span>';
                  }
                },
                { text: 'End Reason', dataIndex: 'endedReason', width: 110,
                  renderer: function (v) {
                      if (!v) { return '—'; }
                      var labels = { manual: 'Ended manually', schedule: 'Schedule expired', exception: 'Exception' };
                      return Ext.htmlEncode(labels[v] || v);
                  }
                }
            ],
            bbar: Ext.create('Ext.toolbar.Paging', {
                store: this.historyStore,
                displayInfo: true,
                displayMsg: '{0} – {1} of {2}'
            })
        });
    },

    loadAll: function () {
        var me = this;
        Ext.Ajax.request({
            url: me.BASE_URL + '/schedules',
            success: function (resp) {
                var data = Ext.decode(resp.responseText);
                var sorted = me._sortAllWindows(data.windows || []);
                me.windowsStore.getProxy().setData(sorted);
                me.windowsStore.loadPage(1);
                var act = data.currentActivation;
                var isActive = act && act.activatedByScheduleWindowId;
                var statusHtml;
                if (isActive) {
                    var wid = Ext.htmlEncode(act.activatedByScheduleWindowId);
                    var reasonPart = act.reason
                        ? '<span style="opacity:.7">·</span>&nbsp;' + Ext.htmlEncode(act.reason)
                        : '';
                    var untilPart = act.expectedEndAt
                        ? '<span style="opacity:.7">·</span>&nbsp;Until:&nbsp;<b>' + Ext.htmlEncode(formatDateTime(act.expectedEndAt)) + '</b>'
                        : '';
                    var fromTime = null;
                    var windows = data.windows || [];
                    for (var i = 0; i < windows.length; i++) {
                        if (windows[i].id === act.activatedByScheduleWindowId) {
                            var aw = windows[i];
                            if (aw.from) {
                                fromTime = aw.from;
                            } else if (act.expectedEndAt && aw.durationMinutes) {
                                fromTime = new Date(new Date(act.expectedEndAt).getTime() - aw.durationMinutes * 60000).toISOString();
                            }
                            break;
                        }
                    }
                    var sincePart = fromTime
                        ? '<span style="opacity:.7">·</span>&nbsp;Since:&nbsp;<b>' + Ext.htmlEncode(formatDateTime(fromTime)) + '</b>'
                        : '';
                    var sep = '&nbsp;&nbsp;';
                    statusHtml =
                        '<div style="display:flex;align-items:center;gap:8px;' +
                        'background:#e6a800;border:1px solid #c98f00;border-radius:4px;padding:6px 12px;color:#fff">' +
                        '<span style="font-size:20px;line-height:1">⚠</span>' +
                        '<span style="font-weight:bold;font-size:13px">Maintenance ACTIVE</span>' +
                        (reasonPart ? sep + '<span style="font-size:12px">' + reasonPart + '</span>' : '') +
                        (sincePart  ? sep + '<span style="font-size:12px">' + sincePart  + '</span>' : '') +
                        (untilPart  ? sep + '<span style="font-size:12px">' + untilPart  + '</span>' : '') +
                        '</div>';
                } else {
                    statusHtml =
                        '<div style="display:flex;align-items:center;gap:10px;' +
                        'background:#388e3c;border:1px solid #2e7d34;border-radius:4px;padding:6px 12px;color:#fff">' +
                        '<span style="font-size:16px;line-height:1">✓</span>' +
                        '<span style="font-size:13px">No active maintenance window</span>' +
                        '</div>';
                }
                me.statusStrip.update(statusHtml);
            },
            failure: function () {
                Ext.Msg.alert('Error', 'Failed to load maintenance data.');
            }
        });
        me.historyStore.loadPage(1);
        me.loadExemptions();
    },

    loadExemptions: function () {
        var me = this;
        var TYPE_ORDER  = ['http', 'ip', 'command', 'builtin'];
        var TYPE_LABELS = { http: 'HTTP', ip: 'IP', command: 'Command', builtin: 'Built-in' };
        Ext.Ajax.request({
            url: me.BASE_URL + '/exemptions',
            success: function (resp) {
                var data = Ext.decode(resp.responseText);
                var list = data.exemptions || [];
                var html;
                if (list.length === 0) {
                    html = '<em style="color:#888">No exemptions configured.</em>';
                } else {
                    // Group by type, preserving TYPE_ORDER then any unknown types last.
                    var groups = {};
                    list.forEach(function (ex) {
                        if (!ex || typeof ex.type !== 'string') { return; }
                        var t = ex.type;
                        if (!groups[t]) { groups[t] = []; }
                        groups[t].push(ex);
                    });

                    var orderedTypes = TYPE_ORDER.filter(function (t) { return groups[t]; });
                    Object.keys(groups).forEach(function (t) {
                        if (orderedTypes.indexOf(t) === -1) { orderedTypes.push(t); }
                    });

                    html = orderedTypes.map(function (type, idx) {
                        var safeType  = TYPE_LABELS.hasOwnProperty(type) ? type : 'unknown';
                        var label     = TYPE_LABELS[type] || Ext.htmlEncode(type.toUpperCase());
                        var chips     = groups[type].map(function (ex) {
                            return '<span class="amm-ex-chip amm-ex-' + safeType + '">' +
                                Ext.htmlEncode(ex.description || '') +
                                '</span>';
                        }).join('');
                        var spacer = idx > 0 ? '<br>' : '';
                        return spacer +
                            '<div style="font-size:10px;font-weight:bold;text-transform:uppercase;' +
                            'color:#aaa;letter-spacing:.06em;margin:2px 0 3px">' + label + '</div>' +
                            chips;
                    }).join('');
                }
                me.exemptionsPanel.update(html);
                me.exemptionsPanel.setTitle('Exemptions (' + list.length + ')');
            },
            failure: function () {
                me.exemptionsPanel.update('<em style="color:#e8a07e">Failed to load exemptions.</em>');
            }
        });
    },

    openCreateModal: function () {
        var me = this;
        var LW = 185;
        var INFO_ICON = '/bundles/pimcoreadmin/img/flat-color-icons/info-gray.svg';

        function lbl(text, required, tip) {
            var s = required
                ? text + ' <span style="color:red;font-weight:bold">*</span>'
                : text;
            if (tip) {
                s += ' <img src="' + INFO_ICON + '" data-qtip="' + Ext.htmlEncode(tip) + '"' +
                     ' style="width:14px;height:14px;vertical-align:middle;margin-left:4px;cursor:help"/>';
            }
            return s;
        }

        var TIMEZONES = [
            'UTC',
            'Europe/Berlin','Europe/London','Europe/Paris','Europe/Amsterdam',
            'Europe/Rome','Europe/Madrid','Europe/Warsaw','Europe/Zurich',
            'America/New_York','America/Chicago','America/Denver','America/Los_Angeles',
            'America/Toronto','America/Sao_Paulo',
            'Asia/Tokyo','Asia/Shanghai','Asia/Singapore','Asia/Dubai','Asia/Kolkata',
            'Australia/Sydney','Pacific/Auckland'
        ];

        function buildIso(dateField, timeField) {
            var d = dateField.getValue();
            if (!d) { return null; }
            var t = timeField.getValue();
            return Ext.Date.format(d, 'Y-m-d') + 'T' +
                   (t ? Ext.Date.format(t, 'H:i') : '00:00') + ':00';
        }

        function buildTimestamp(dateField, timeField) {
            var d = dateField.getValue();
            if (!d) { return null; }
            var ts = new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0, 0, 0, 0);
            var t = timeField.getValue();
            if (t) { ts.setHours(t.getHours(), t.getMinutes(), 0, 0); }
            return ts;
        }

        function isValidCronSegment(seg, min, max) {
            if (seg === '*') { return true; }
            var m;
            m = seg.match(/^\*\/(\d+)$/);
            if (m) { return parseInt(m[1], 10) >= 1; }
            m = seg.match(/^(\d+)-(\d+)(?:\/(\d+))?$/);
            if (m) {
                var lo = parseInt(m[1], 10), hi = parseInt(m[2], 10);
                var s = m[3] ? parseInt(m[3], 10) : null;
                return lo >= min && hi <= max && lo <= hi && (s === null || s >= 1);
            }
            m = seg.match(/^(\d+)(?:\/(\d+))?$/);
            if (m) {
                var v = parseInt(m[1], 10), sv = m[2] ? parseInt(m[2], 10) : null;
                return v >= min && v <= max && (sv === null || sv >= 1);
            }
            return false;
        }

        function validateCron(expr) {
            if (!expr || !expr.trim()) { return false; }
            var parts = expr.trim().split(/\s+/);
            if (parts.length !== 5) { return false; }
            var ranges = [[0,59],[0,23],[1,31],[1,12],[0,7]];
            for (var i = 0; i < 5; i++) {
                var segs = parts[i].split(',');
                for (var j = 0; j < segs.length; j++) {
                    if (!isValidCronSegment(segs[j], ranges[i][0], ranges[i][1])) { return false; }
                }
            }
            return true;
        }

        /* ---------- Type ---------- */
        var typeCombo = Ext.create('Ext.form.field.ComboBox', {
            fieldLabel: lbl('Type', true,
                'One-time: single window with a fixed start and end date/time. ' +
                'Recurring: repeating window driven by a cron expression.'),
            value: 'one-time',
            store: [['one-time', 'One-time'], ['recurring', 'Recurring']],
            editable: false,
            forceSelection: true,
            allowBlank: false,
            anchor: '100%',
            labelWidth: LW
        });

        /* ---------- From (date + time) — one-time only, required ---------- */
        var fromDate = Ext.create('Ext.form.field.Date', {
            flex: 1, format: 'd.m.Y', emptyText: 'dd.mm.yyyy', allowBlank: false
        });
        var fromTime = Ext.create('Ext.form.field.Time', {
            width: 90, format: 'H:i', increment: 15, emptyText: 'HH:MM',
            margin: '0 0 0 6', allowBlank: true
        });
        var fromContainer = Ext.create('Ext.form.FieldContainer', {
            fieldLabel: lbl('From', true,
                'Start date and time of the maintenance window in the chosen timezone.'),
            labelWidth: LW, layout: 'hbox', anchor: '100%',
            items: [fromDate, fromTime]
        });

        /* ---------- To (date + time) — one-time only, required ---------- */
        var toDate = Ext.create('Ext.form.field.Date', {
            flex: 1, format: 'd.m.Y', emptyText: 'dd.mm.yyyy', allowBlank: false,
            validator: function () {
                var toTs = buildTimestamp(toDate, toTime);
                var fromTs = buildTimestamp(fromDate, fromTime);
                if (toTs && fromTs && toTs <= fromTs) {
                    return '"To" must be after "From" (date and time)';
                }
                return true;
            }
        });
        var toTime = Ext.create('Ext.form.field.Time', {
            width: 90, format: 'H:i', increment: 15, emptyText: 'HH:MM',
            margin: '0 0 0 6', allowBlank: true
        });
        var toContainer = Ext.create('Ext.form.FieldContainer', {
            fieldLabel: lbl('To', true,
                'End date and time of the maintenance window in the chosen timezone.'),
            labelWidth: LW, layout: 'hbox', anchor: '100%',
            items: [toDate, toTime]
        });

        fromDate.on('change', function () { toDate.validate(); });
        fromTime.on('change', function () { toDate.validate(); });
        toTime.on('change', function () { toDate.validate(); });

        /* ---------- Cron Expression — recurring only, required ---------- */
        var cronField = Ext.create('Ext.form.field.Text', {
            fieldLabel: lbl('Cron Expression', true,
                'Standard 5-field cron syntax evaluated in the chosen timezone. ' +
                'Examples: "0 2 * * *" (daily at 02:00), "0 3 * * 6" (every Saturday at 03:00).'),
            emptyText: 'e.g. 0 2 * * *',
            allowBlank: true,   // starts disabled — not validated until recurring is chosen
            anchor: '100%', labelWidth: LW, hidden: true, disabled: true,
            validator: function (value) {
                if (!value || !value.trim()) { return true; }
                return validateCron(value)
                    ? true
                    : 'Invalid cron expression. Expected 5 fields: minute hour day month weekday (e.g. "0 2 * * *")';
            }
        });

        /* ---------- Duration — recurring only, required ---------- */
        var durationField = Ext.create('Ext.form.field.Number', {
            fieldLabel: lbl('Duration (minutes)', true,
                'How long the recurring maintenance window lasts each time it fires.'),
            emptyText: 'e.g. 60',
            minValue: 1, allowDecimals: false, allowBlank: true,
            anchor: '100%', labelWidth: LW, hidden: true, disabled: true
        });

        /* ---------- Timezone ---------- */
        var timezoneCombo = Ext.create('Ext.form.field.ComboBox', {
            fieldLabel: lbl('Timezone', true,
                'Timezone applied to the From/To dates and the cron schedule. Defaults to UTC.'),
            value: 'UTC',
            store: TIMEZONES, editable: true, forceSelection: false,
            emptyText: 'Select or type timezone…',
            allowBlank: false,
            anchor: '100%', labelWidth: LW
        });

        /* ---------- Reason ---------- */
        var reasonField = Ext.create('Ext.form.field.Text', {
            fieldLabel: lbl('Reason', false,
                'Optional description shown to users during the window and stored in the history log.'),
            emptyText: 'e.g. Database migration v2.4',
            anchor: '100%', labelWidth: LW
        });

        /* ---------- Announce Before ---------- */
        var announceField = Ext.create('Ext.form.field.Number', {
            fieldLabel: lbl('Announce Before (min)', false,
                'Display a warning banner to admin users this many minutes before the window starts. Set to 0 to disable.'),
            value: 0, minValue: 0, allowDecimals: false,
            emptyText: 'e.g. 15',
            anchor: '100%', labelWidth: LW
        });

        /* ---------- Type toggle — swap visible fields and flip allowBlank ---------- */
        typeCombo.on('change', function (combo, newValue) {
            var isRecurring = newValue === 'recurring';

            fromContainer.setVisible(!isRecurring);
            fromDate.setDisabled(isRecurring);
            fromDate.allowBlank = isRecurring;
            fromDate.clearInvalid();

            toContainer.setVisible(!isRecurring);
            toDate.setDisabled(isRecurring);
            toDate.allowBlank = isRecurring;
            toDate.clearInvalid();

            cronField.setVisible(isRecurring);
            cronField.setDisabled(!isRecurring);
            cronField.allowBlank = !isRecurring;
            cronField.clearInvalid();

            durationField.setVisible(isRecurring);
            durationField.setDisabled(!isRecurring);
            durationField.allowBlank = !isRecurring;
            durationField.clearInvalid();
        });

        /* ---------- Form ---------- */
        var form = Ext.create('Ext.form.Panel', {
            bodyPadding: 14,
            items: [
                typeCombo,
                fromContainer,
                toContainer,
                cronField,
                durationField,
                timezoneCombo,
                reasonField,
                announceField,
                {
                    xtype: 'fieldset',
                    title: t('Scope (leave empty for global maintenance)'),
                    collapsible: true,
                    collapsed: true,
                    itemId: 'scopeFieldset',
                    anchor: '100%',
                    items: [
                        {
                            xtype: 'fieldcontainer',
                            fieldLabel: t('Path prefixes'),
                            itemId: 'pathPrefixContainer',
                            labelWidth: LW,
                            layout: 'vbox',
                            anchor: '100%',
                            items: []
                        },
                        {
                            xtype: 'button',
                            text: t('+ Add path prefix'),
                            margin: '4 0 8 0',
                            handler: function () {
                                var fieldset = this.up('[itemId=scopeFieldset]');
                                var container = fieldset.down('[itemId=pathPrefixContainer]');
                                container.add({
                                    xtype: 'textfield',
                                    name: 'pathPrefix',
                                    emptyText: '/shop',
                                    width: 300,
                                    margin: '2 0 2 0'
                                });
                                container.updateLayout();
                            }
                        },
                        {
                            xtype: 'textfield',
                            name: 'siteIds',
                            fieldLabel: t('Site IDs (comma-separated)'),
                            labelWidth: LW,
                            emptyText: '1,2,3',
                            anchor: '100%'
                        }
                    ]
                },
                {
                    xtype: 'component',
                    html: '<div style="color:#888;font-size:11px;margin-top:10px">' +
                          '<span style="color:red;font-weight:bold">*</span> Required field</div>'
                }
            ]
        });

        /* ---------- Window ---------- */
        var win = Ext.create('Ext.window.Window', {
            title: 'Schedule Maintenance Window',
            width: 580,
            modal: true,
            layout: 'fit',
            items: [form],
            buttons: [{
                text: 'Save',
                iconCls: 'pimcore_icon_save',
                handler: function () {
                    if (!form.isValid()) {
                        return;
                    }

                    var isRecurring = typeCombo.getValue() === 'recurring';

                    // Collect path prefix values
                    var pathPrefixContainer = form.down('[itemId=pathPrefixContainer]');
                    var pathPrefixes = [];
                    if (pathPrefixContainer) {
                        pathPrefixContainer.items.each(function (field) {
                            var val = field.getValue ? field.getValue() : null;
                            if (val && val.trim() !== '') {
                                pathPrefixes.push(val.trim());
                            }
                        });
                    }

                    // Collect site IDs
                    var siteIdsField = form.down('[name=siteIds]');
                    var siteIds = [];
                    if (siteIdsField) {
                        var raw = siteIdsField.getValue();
                        if (raw && raw.trim() !== '') {
                            siteIds = raw.split(',').map(function (s) {
                                return parseInt(s.trim(), 10);
                            }).filter(function (n) { return !isNaN(n); });
                        }
                    }

                    var payload = {
                        type:                  typeCombo.getValue(),
                        from:                  isRecurring ? null : buildIso(fromDate, fromTime),
                        to:                    isRecurring ? null : buildIso(toDate, toTime),
                        cronExpression:        isRecurring ? (cronField.getValue() || null) : null,
                        durationMinutes:       isRecurring ? (durationField.getValue() || null) : null,
                        timezone:              timezoneCombo.getValue() || 'UTC',
                        reason:                reasonField.getValue() || null,
                        announceBeforeMinutes: announceField.getValue() || 0,
                        pathPrefixes:          pathPrefixes,
                        siteIds:               siteIds
                    };

                    function doCreate(forceCreate) {
                        Ext.Ajax.request({
                            url: me.BASE_URL + '/schedules',
                            method: 'POST',
                            jsonData: Ext.apply({}, payload, { forceCreate: forceCreate }),
                            success: function (resp) {
                                var data = Ext.decode(resp.responseText, true) || {};
                                if (data.overlapping) {
                                    Ext.Msg.confirm(
                                        'Overlap Detected',
                                        'This window overlaps with the following existing schedule(s):<br><br>' +
                                        data.overlapping.map(function (id) {
                                            return '&bull; ' + Ext.htmlEncode(id);
                                        }).join('<br>') +
                                        '<br><br>Do you want to create it anyway?',
                                        function (btn) {
                                            if (btn === 'yes') { doCreate(true); }
                                        }
                                    );
                                } else {
                                    win.close();
                                    me.loadAll();
                                }
                            },
                            failure: function (resp) {
                                var err = Ext.decode(resp.responseText, true) || {};
                                Ext.Msg.alert('Error', err.error || 'Request failed');
                            }
                        });
                    }

                    doCreate(false);
                }
            }, {
                text: 'Cancel',
                handler: function () { win.close(); }
            }]
        });

        win.show();
    },

    endNow: function (id) {
        var me = this;
        Ext.Ajax.request({
            url: me.BASE_URL + '/schedules/' + id + '/end-now',
            method: 'POST',
            success: function () { me.loadAll(); },
            failure: function (resp) {
                var err = Ext.decode(resp.responseText, true) || {};
                Ext.Msg.alert('Error', err.error || 'Request failed');
            }
        });
    },

    deleteWindow: function (id) {
        var me = this;
        Ext.Ajax.request({
            url: me.BASE_URL + '/schedules/' + id,
            method: 'DELETE',
            success: function () { me.loadAll(); },
            failure: function (resp) {
                var err = Ext.decode(resp.responseText, true) || {};
                Ext.Msg.alert('Error', err.error || 'Request failed');
            }
        });
    }
});

new pimcore.bundle.twochain_advanced_maintenance_mode.startup();
