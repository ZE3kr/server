<!--
  - @copyright 2023 Christopher Ng <chrng8@gmail.com>
  -
  - @author Christopher Ng <chrng8@gmail.com>
  -
  - @license AGPL-3.0-or-later
  -
  - This program is free software: you can redistribute it and/or modify
  - it under the terms of the GNU Affero General Public License as
  - published by the Free Software Foundation, either version 3 of the
  - License, or (at your option) any later version.
  -
  - This program is distributed in the hope that it will be useful,
  - but WITHOUT ANY WARRANTY; without even the implied warranty of
  - MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
  - GNU Affero General Public License for more details.
  -
  - You should have received a copy of the GNU Affero General Public License
  - along with this program. If not, see <http://www.gnu.org/licenses/>.
  -
-->

<template>
	<NcSelectTags v-show="showSystemTags"
		class="system-tags"
		v-model="appliedTags"
		:taggable="true"
		:push-tags="true"
		:create-option="createOption"
		:reduce="reduce"
		@option:selected="selectTag"
		@option:created="createTag"
		@option:deselected="deleteTag"
	/>
</template>

<script>
import NcSelectTags from '@nextcloud/vue/dist/Components/NcSelectTags.js'

import axios from '@nextcloud/axios'
import { generateRemoteUrl } from '@nextcloud/router'

export default {
	name: 'SystemTags',

	components: {
		NcSelectTags,
	},

	props: {
		fileInfo: {
			type: Object,
			required: true,
		},
		showSystemTags: {
			type: Boolean,
			required: true,
		},
	},

	data() {
		return {
			appliedTags: [],
			selectedTagsCollection: null,
			systemTagsCollection: OC.SystemTags.collection,
		}
	},

	mounted() {
		this.fetchTags()
	},

	computed: {
		baseUrl() {
			return generateRemoteUrl('dav') + '/systemtags-relations/files/' + this.fileInfo.id + '/'
		},
	},

	methods: {
		// TODO Feature parity
		// - apps/systemtags/src/systemtagsinfoview.js
		// - core/src/systemtags/systemtagsinputfield.js
		// - core/src/systemtags/systemtagsmappingcollection.js

		// DONE Delete
		// DONE Select
		// TODO Create - partial
		fetchTags() {
			this.selectedTagsCollection = new OC.SystemTags.SystemTagsMappingCollection([], {
				objectType: 'files',
				objectId: this.fileInfo.id,
			})

			this.selectedTagsCollection.fetch({
				success: (collection) => {
					collection.fetched = true
					this.appliedTags = collection.map(model => Number(model.toJSON().id))
					if (this.appliedTags.length === 0) {
						if (this.showSystemTags) {
							this.$emit('update:show-system-tags', false)
						}
					}
				},
			})
		},

		/**
		 * @return {object}
		 */
		createOption(displayName) {
			// FIXME displayName error
			return {
				displayName: String(displayName),
				canAssign: true,
				userAssignable: true,
				userVisible: true,
				// NOTE lib/private/SystemTag/SystemTagManager.php
				id: Math.max(...this.appliedTags) + 1,
			}
		},

		/**
		 * @return {number}
		 */
		reduce(option) {
			// console.log(option)
			return option.id
		},

		async selectTag(tags) {
			const tag = tags[tags.length - 1]
			// Limit to only existing tags which have an id
			if (tag.id) {
				await axios.put(this.baseUrl + tag.id)
			}
		},

		/**
		 * Create the global system tag and assign the tag to the file
		 *
		 * @param {object} tag
		 */
		async createTag(tag) {
			// FIXME Cannot read properties of undefined (reading 'displayName')
			// FIXME v-model handling should be number[] or object[]? NcSelectTags?
			// debugger

			const tagToPost = {
				...tag,
				name: tag.displayName,
			}

			delete tagToPost.displayName

			// await axios.post(generateRemoteUrl('dav') + '/systemtags', tagToPost)
			this.systemTagsCollection.create(tagToPost, {
				success: (model) => {
					const tagToPut = {
						...tagToPost,
						id: model.id,
					}

					axios.put(this.baseUrl + tagToPut.id, tagToPut)
				}
			})
		},

		async deleteTag(tag) {
			await axios.delete(this.baseUrl + tag.id)
		},
	},
}
</script>

<style lang="scss" scoped>
.system-tags {
	width: 100%;
	:deep {
		.vs__deselect {
			padding: 0;
		}
	}
}
</style>
