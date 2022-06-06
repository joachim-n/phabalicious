(window.webpackJsonp=window.webpackJsonp||[]).push([[24],{444:function(t,e,a){"use strict";a.r(e);var s=a(55),n=Object(s.a)({},(function(){var t=this,e=t.$createElement,a=t._self._c||e;return a("ContentSlotsDistributor",{attrs:{"slot-key":t.$parent.slotKey}},[a("h1",{attrs:{id:"inheritance"}},[a("a",{staticClass:"header-anchor",attrs:{href:"#inheritance"}},[t._v("#")]),t._v(" Inheritance")]),t._v(" "),a("p",[t._v("Sometimes it make sense to extend an existing configuration or to include configuration from other places from the file-system or from remote locations. There's a special key "),a("code",[t._v("inheritsFrom")]),t._v(" which will include the yaml found at the location and merge it with the data. This is supported for entries in "),a("code",[t._v("hosts")]),t._v(" and "),a("code",[t._v("dockerHosts")]),t._v(" and for the fabfile itself.")]),t._v(" "),a("p",[t._v("If a "),a("code",[t._v("host")]),t._v(", a "),a("code",[t._v("dockerHost")]),t._v(" or the fabfile itself has the key "),a("code",[t._v("inheritsFrom")]),t._v(", then the given key is used as a base-configuration. Here's a simple example:")]),t._v(" "),a("div",{staticClass:"language-yaml extra-class"},[a("pre",{pre:!0,attrs:{class:"language-yaml"}},[a("code",[a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("hosts")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n  "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("default")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("port")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" "),a("span",{pre:!0,attrs:{class:"token number"}},[t._v("22")]),t._v("\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("host")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" localhost\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("user")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" default\n  "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("example1")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("inheritsFrom")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" default\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("port")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" "),a("span",{pre:!0,attrs:{class:"token number"}},[t._v("23")]),t._v("\n  "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("example2")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("inheritsFrom")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" example1\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("user")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" example2\n")])])]),a("p",[a("code",[t._v("example1")]),t._v(" will store the merged configuration from "),a("code",[t._v("default")]),t._v(" with the configuration of "),a("code",[t._v("example1")]),t._v(". "),a("code",[t._v("example2")]),t._v(" is a merge of all three configurations: "),a("code",[t._v("example2")]),t._v(" with "),a("code",[t._v("example1")]),t._v(" with "),a("code",[t._v("default")]),t._v(".")]),t._v(" "),a("div",{staticClass:"language-yaml extra-class"},[a("pre",{pre:!0,attrs:{class:"language-yaml"}},[a("code",[a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("hosts")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n  "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("example1")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("port")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" "),a("span",{pre:!0,attrs:{class:"token number"}},[t._v("23")]),t._v("\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("host")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" localhost\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("user")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" default\n  "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("example2")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("port")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" "),a("span",{pre:!0,attrs:{class:"token number"}},[t._v("23")]),t._v("\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("host")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" localhost\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("user")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" example2\n")])])]),a("p",[t._v("You can even reference external files to inherit from:")]),t._v(" "),a("div",{staticClass:"language-yaml extra-class"},[a("pre",{pre:!0,attrs:{class:"language-yaml"}},[a("code",[a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("hosts")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n  "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("fileExample")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("inheritsFrom")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" ./path/to/config/file.yaml\n  "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("httpExample")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n    "),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("inheritsFrom")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" http"),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("//my.tld/path/to/config_file.yaml\n")])])]),a("p",[t._v("This mechanism works also for the fabfile.yaml / index.yaml itself, and is not limited to one entry:")]),t._v(" "),a("div",{staticClass:"language-yaml extra-class"},[a("pre",{pre:!0,attrs:{class:"language-yaml"}},[a("code",[a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("name")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v(" test fabfile\n\n"),a("span",{pre:!0,attrs:{class:"token key atrule"}},[t._v("inheritsFrom")]),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v(":")]),t._v("\n  "),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v("-")]),t._v(" ./mbb.yaml\n  "),a("span",{pre:!0,attrs:{class:"token punctuation"}},[t._v("-")]),t._v(" ./drupal.yaml\n")])])]),a("h2",{attrs:{id:"inherit-from-a-blueprint"}},[a("a",{staticClass:"header-anchor",attrs:{href:"#inherit-from-a-blueprint"}},[t._v("#")]),t._v(" Inherit from a blueprint")]),t._v(" "),a("p",[t._v("You can even inherit from a blueprint configuration for a host-config. This host-config can then override specific parts.")]),t._v(" "),a("div",{staticClass:"language- extra-class"},[a("pre",{pre:!0,attrs:{class:"language-text"}},[a("code",[t._v("host:\n  demo:\n    inheritFromBlueprint:\n      config: my-blueprint-config\n      varian: the-variant\n")])])]),a("h2",{attrs:{id:"inherit-a-blueprint-from-an-existing-blueprint"}},[a("a",{staticClass:"header-anchor",attrs:{href:"#inherit-a-blueprint-from-an-existing-blueprint"}},[t._v("#")]),t._v(" Inherit a blueprint from an existing blueprint")]),t._v(" "),a("p",[a("code",[t._v("inheritsFrom")]),t._v(" is not supported for blueprints, they will be resolved after the config got created. But you can use "),a("code",[t._v("blueprintInheritsFrom")]),t._v(" instead. An example:")]),t._v(" "),a("div",{staticClass:"language- extra-class"},[a("pre",{pre:!0,attrs:{class:"language-text"}},[a("code",[t._v("dockerHosts:\n  hostA:\n    blueprint:\n      key: hello-world\n\nhosts:\n  hostA:\n    blueprint:\n      blueprintInheritsFrom:\n        - docker:hostA\n\n  hostB:\n    blueprint:\n      blueprintInheritsFrom:\n        - host:hostA\n")])])]),a("p",[t._v("As blueprints can be part of the general section, a dockerHost-confg or a host config, they need a namespace, so phab knows exactly which blueprint config you want to inherit from.")])])}),[],!1,null,null,null);e.default=n.exports}}]);