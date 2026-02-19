# Zabbix Incident Investigation Module

<img width="1280" height="755" alt="image" src="https://github.com/user-attachments/assets/eb8005f9-43de-45fa-8525-288d47dbec4c" />


A Zabbix module providing detailed incident investigation with temporal patterns, occurrence heatmaps, SLA impact, and action timeline.

**Developed by [Monzphere](https://monzphere.com)**

---

## What the module provides

- **Temporal patterns**: heatmap showing which weekdays and hours incidents occur most frequently
- **Monthly drill-down**: click a month to see daily distribution of incidents
- **Monthly comparison**: trend (↗ ↘ →) and percentage change vs previous month
- **SLA by service**: impact on affected services with SLI/SLO, visual gauge and compact tree
- **Timeline and Actions**: toggle between event list and actions table (alerts, commands, status) using native Zabbix layout
- **Recommendations**: highest-risk hours and days for planning
- **Quick comparison**: "Same slot last week" and "Same day last week" when filters are applied
- **Export**: report exportable via print/PDF
- **Direct access**: magnifying glass icon (🔍) on the problem list and problem widgets that opens investigation for that event

---

## Requirements

- Zabbix 7.0.x (created and tested on 7.0.x; may work on higher versions)
- PHP 8.0+
- Zabbix with Services and SLA configured (optional, for SLA impact panel)

If the module does not work on your Zabbix version, please open an [issue](https://github.com/YOUR-USERNAME/timepattern-incident-investigation/issues) on GitHub.

---

## Installation

### 1. Download the module

```bash
git clone https://github.com/YOUR-USERNAME/timepattern-incident-investigation.git
# or download the ZIP and extract
```

### 2. Copy to Zabbix

Copy the module folder to Zabbix's modules directory:

```bash
cp -r timepattern-incident-investigation /usr/share/zabbix/modules/TimePattern
```

If the `modules` directory does not exist, create it:

```bash
mkdir -p /usr/share/zabbix/modules
cp -r timepattern-incident-investigation /usr/share/zabbix/modules/TimePattern
```

### 3. Enable the module in Zabbix

1. Go to **Administration → General → Modules**
2. Find **Incident Investigation**
3. Click **Enable**

---

## How to use

The module does not add a menu item. Access is through:

- **From the problem list**: in **Monitoring → Problems**, click the magnifying glass icon (🔍) next to each problem to open the investigation for that event
- **From problem widgets**: the same icon appears on problem widgets in dashboards
- **Direct URL**: `zabbix.php?action=incident.investigation.view&eventid=12345` (replace with the desired eventid)

---

## Module structure

```
TimePattern/
├── Module.php
├── manifest.json
├── actions/
│   ├── CControllerIncidentInvestigationView.php
│   └── CControllerIncidentServiceImpact.php
├── assets/
│   ├── css/
│   │   ├── blue-theme.css
│   │   └── dark-theme.css
│   └── js/
│       └── problem-investigation-icon.js
├── views/
│   ├── incident.investigation.view.php
│   ├── layout.htmlpage.php
│   └── js/
│       └── incident.investigation.js.php
└── README.md
```

---

## License

Open source and free for the community.

---

## Acknowledgments

We thank our partner **[Lunio](https://luniobr.com)** for investing in collaboration toward open source and free solutions, contributing to the monitoring ecosystem and supporting the Zabbix community.

---

**Monzphere** – [monzphere.com](https://monzphere.com)
