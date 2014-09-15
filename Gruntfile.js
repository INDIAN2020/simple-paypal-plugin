module.exports = function(grunt) {

	// Project configuration.
	grunt.initConfig({
		pkg: grunt.file.readJSON('package.json'),
		less: {
			css: {
				options: {
					paths: ["less"],
					banner: "/*\nPlugin Name: <%= pkg.plugin_name %>\nPlugin URI: <%= pkg.homepage %>\nAuthor: <%= pkg.author %> <<%= pkg.author_email %>>\nLicense: MIT\n*/",
					cleancss:true
				},
				files: {
					'css/<%= pkg.name %>.css': 'less/<%= pkg.name %>.less',
					'css/admin.css': 'less/admin.less'

				}
			}
		},
		pot: {
			options: {
				"text_domain": "<%= pkg.text_domain %>",
				"dest": "languages/<%= pkg.text_domain %>.pot",
				"encoding": "UTF-8",
				"language": "PHP",
				"keywords": [ //WordPress localisation functions
					'__:1',
					'_e:1',
					'_x:1,2c',
					'esc_html__:1',
					'esc_html_e:1',
					'esc_html_x:1,2c',
					'esc_attr__:1', 
					'esc_attr_e:1', 
					'esc_attr_x:1,2c', 
					'_ex:1,2c',
					'_n:1,2', 
					'_nx:1,2,4c',
					'_n_noop:1,2',
					'_nx_noop:1,2,3c'
				],
				"package_name": "<%= pkg.name %>",
				"package_version": "<%= pkg.version %>",
				"msgid_bugs_address": "<%= pkg.author_email %>"
			},
			files: {
				src: [ '*.php' ],
				expand: true
			},
		},
		checktextdomain: {
			options:{
      			text_domain: 'sppp',
				correct_domain: false, //Will correct missing/variable domains
				keywords: [ //WordPress localisation functions
					'__:1,2d',
					'_e:1,2d',
					'_x:1,2c,3d',
					'esc_html__:1,2d',
					'esc_html_e:1,2d',
					'esc_html_x:1,2c,3d',
					'esc_attr__:1,2d', 
					'esc_attr_e:1,2d', 
					'esc_attr_x:1,2c,3d', 
					'_ex:1,2c,3d',
					'_n:1,2,4d', 
					'_nx:1,2,4c,5d',
					'_n_noop:1,2,3d',
					'_nx_noop:1,2,3c,4d'
				],
			},
			files: {
				src: [ '*.php' ],
				expand: true
			},
		},
		po2mo: {
			files: {
				src: 'languages/*.po',
				expand: true
			}
		},
		sprite:{
			buttonsdefault: {
				src: 'img/buttons/default/*.png',
				destImg: 'img/src/spritesheet-default.png',
				destCSS: 'less/spritesheet-default.less',
				imgPath: '../img/spritesheet-default.png',
				algorithm: 'binary-tree'
			},
			buttonspm: {
				src: 'img/buttons/postmodular/*.png',
				destImg: 'img/src/spritesheet-postmodular.png',
				destCSS: 'less/spritesheet-postmodular.less',
				imgPath: '../img/spritesheet-postmodular.png',
				algorithm: 'binary-tree'
			}
		},
		imagemin: {
    		spritesheets: {
				options: {
					optimizationLevel: 7
				},
				files: {
					'img/spritesheet-default.png': 'img/src/spritesheet-default.png',
					'img/spritesheet-postmodular.png': 'img/src/spritesheet-postmodular.png'
				}
			}
		},
		watch: {
			css: {
				files: ['less/*.less'],
				tasks: ['less'],
				options: {
	  				spawn: false,
	  			}
	  		},
	  		img: {
	  			files: ['img/buttons/*.png'],
	  			tasks: ['images'],
				options: {
	  				spawn: false,
	  			}
	  		}
		}
	});

	// Load the plugins that provide the tasks.
	grunt.loadNpmTasks('grunt-contrib-concat');
	grunt.loadNpmTasks('grunt-contrib-uglify');
	grunt.loadNpmTasks('grunt-contrib-less');
	grunt.loadNpmTasks('grunt-pot');
	grunt.loadNpmTasks('grunt-po2mo');
	grunt.loadNpmTasks('grunt-checktextdomain');
	grunt.loadNpmTasks('grunt-contrib-watch');
	grunt.loadNpmTasks('grunt-banner');
	grunt.loadNpmTasks('grunt-spritesmith');
	grunt.loadNpmTasks('grunt-contrib-jshint');
	grunt.loadNpmTasks('grunt-contrib-imagemin');

	// Default task
	grunt.registerTask('default', ['watch']);
	// Image processing tasks
	grunt.registerTask( 'images', ['sprite', 'imagemin', 'less']);
};